<?php

namespace JanDev\EmailSystem\Console\Commands;

use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Support\PmtaSpooler;
use JanDev\EmailSystem\Support\SenderResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PmtaSync extends Command
{
    protected $signature = 'email:pmta-sync';
    protected $description = 'Sync spooled EML files to PMTA servers and mark emails as sent';

    /**
     * SSH ControlMaster options — unique ControlPath per serverName.
     */
    protected function sshControlMaster(string $serverName): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/', '_', strtolower($serverName));
        return sprintf(
            '-o ControlMaster=auto -o ControlPath=/tmp/ssh-pmta-%s-%%r@%%h:%%p -o ControlPersist=120',
            $safe
        );
    }

    public function handle(): int
    {
        $spoolBase = storage_path('app/mailspool');

        // Ensure base dirs
        foreach (['outgoing', 'sent', 'failed'] as $dir) {
            $path = $spoolBase . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }

        // Clean up sent/ files older than 7 days (includes subdirectories)
        $this->cleanOldSentFiles($spoolBase . '/sent');

        // === PHASE 1: Generate EML files for all spooled email_logs ===
        $spooledQuery = EmailLog::where('status', 'spooled');
        $spooledCount = $spooledQuery->count();

        if ($spooledCount === 0) {
            Log::channel('queue')->info('PmtaSync: no spooled emails found');
            return 0;
        }

        Log::channel('queue')->info("PmtaSync: found {$spooledCount} spooled emails");

        // Track EML generation count per server to enforce batch_size
        $serverBatchCount = [];

        foreach ($spooledQuery->cursor() as $emailLog) {
            if (!$emailLog->sender_name) {
                Log::channel('queue')->warning("PmtaSync: spooled email {$emailLog->id} has no sender_name, skipping");
                continue;
            }

            $senderConfig = SenderResolver::get($emailLog->sender_name);
            if (!$senderConfig || ($senderConfig['type'] ?? '') !== 'pmta') {
                Log::channel('queue')->warning("PmtaSync: email {$emailLog->id} sender '{$emailLog->sender_name}' is not pmta type, skipping");
                continue;
            }

            // Resolve target server: domain routing first, then sender's pmta_server reference, then inline
            $resolvedServer = SenderResolver::resolveServerForRecipient($emailLog->recipient);
            if ($resolvedServer === null && !empty($senderConfig['pmta_server'])) {
                $resolvedServer = SenderResolver::pmtaServer($senderConfig['pmta_server']);
            }

            $serverName = $resolvedServer['name'] ?? null;

            // Apply batch_size limit per server (only for named servers)
            if ($serverName !== null && $resolvedServer !== null) {
                $batchSize = $resolvedServer['batch_size'] ?? null;
                if ($batchSize !== null) {
                    $count = $serverBatchCount[$serverName] ?? 0;
                    if ($count >= (int) $batchSize) {
                        continue; // Batch limit reached for this server
                    }
                    $serverBatchCount[$serverName] = $count + 1;
                }
            }

            // Check if EML already exists (idempotent)
            $expectedPath = $serverName !== null
                ? $spoolBase . '/outgoing/' . $serverName . '/email_' . $emailLog->id
                : $spoolBase . '/outgoing/email_' . $emailLog->id;

            if (is_file($expectedPath)) {
                continue; // Already spooled
            }

            // Write EML file
            $spooler = new PmtaSpooler($senderConfig, $spoolBase, $resolvedServer, $serverName);
            try {
                $spooler->writeEml($emailLog);
            } catch (\RuntimeException $e) {
                Log::channel('queue')->error("PmtaSync: failed to write EML for email {$emailLog->id}: " . $e->getMessage());
            }
        }

        // === PHASE 2: Sync per-server subdirectories ===
        $this->syncServerDirectories($spoolBase);

        // === PHASE 3: Sync legacy flat outgoing/email_* files ===
        $this->syncLegacyFlatFiles($spoolBase);

        return 0;
    }

    /**
     * Sync each outgoing/{serverName}/ subdirectory to the corresponding PMTA server.
     */
    protected function syncServerDirectories(string $spoolBase): void
    {
        $outgoing = $spoolBase . '/outgoing';

        // Discover server subdirectories
        $serverDirs = [];
        foreach (glob($outgoing . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $serverName = basename($dir);
            $serverDirs[$serverName] = $dir;
        }

        if (empty($serverDirs)) {
            return;
        }

        foreach ($serverDirs as $serverName => $serverDir) {
            $serverConfig = SenderResolver::pmtaServer($serverName);

            if ($serverConfig === null) {
                Log::channel('queue')->warning("PmtaSync: no server config found for directory '{$serverName}', skipping");
                continue;
            }

            $sentDir = $spoolBase . '/sent/' . $serverName;
            if (!is_dir($sentDir)) {
                mkdir($sentDir, 0775, true);
            }

            $this->processServer($serverName, $serverDir, $sentDir, $serverConfig);
        }
    }

    /**
     * Sync legacy flat outgoing/email_* files (backward compat: senders with inline pmta_host).
     * Groups flat files by resolved server, then syncs each group.
     */
    protected function syncLegacyFlatFiles(string $spoolBase): void
    {
        $outgoing = $spoolBase . '/outgoing';
        $flatFiles = glob($outgoing . '/email_*') ?: [];
        $flatFiles = array_filter($flatFiles, 'is_file');

        if (empty($flatFiles)) {
            return;
        }

        Log::channel('queue')->info('PmtaSync: processing ' . count($flatFiles) . ' legacy flat files');

        // Group flat files by resolved server (from email_log's sender config)
        $byServer = []; // serverKey (host:port) => ['config' => ..., 'files' => [...]]

        // Batch-load email_log IDs to avoid N+1 queries
        $fileMap = []; // id => filePath
        foreach ($flatFiles as $filePath) {
            $basename = basename($filePath);
            if (preg_match('/^email_(\d+)$/', $basename, $m)) {
                $fileMap[(int) $m[1]] = $filePath;
            }
        }

        $emailLogs = EmailLog::whereIn('id', array_keys($fileMap))->get()->keyBy('id');

        foreach ($fileMap as $id => $filePath) {
            $emailLog = $emailLogs->get($id);

            if (!$emailLog || !$emailLog->sender_name) {
                continue;
            }

            $senderConfig = SenderResolver::get($emailLog->sender_name);
            if (!$senderConfig || ($senderConfig['type'] ?? '') !== 'pmta') {
                continue;
            }

            // Resolve full config (handles both pmta_server reference and inline pmta_host)
            $fullConfig = SenderResolver::resolveFullPmtaConfig($senderConfig);
            $host = $fullConfig['host'] ?? '';

            if (empty($host)) {
                Log::channel('queue')->warning("PmtaSync: cannot resolve host for legacy file " . basename($filePath));
                continue;
            }

            $serverKey = $host . ':' . ($fullConfig['port'] ?? 22);
            if (!isset($byServer[$serverKey])) {
                $byServer[$serverKey] = ['config' => $fullConfig, 'files' => []];
            }
            $byServer[$serverKey]['files'][] = $filePath;
        }

        foreach ($byServer as $serverKey => $data) {
            $sentDir = $spoolBase . '/sent';
            $this->processLegacyFiles($serverKey, $data['files'], $spoolBase . '/outgoing', $sentDir, $data['config']);
        }
    }

    /**
     * Sync a server subdirectory to the remote PMTA server.
     * rsync source: outgoing/{serverName}/ (NOT the parent outgoing/)
     */
    protected function processServer(string $serverName, string $serverDir, string $sentDir, array $serverConfig): void
    {
        $key  = $serverConfig['ssh_key'] ?? '';
        $host = $serverConfig['host'] ?? '';
        $user = $serverConfig['user'] ?? 'root';
        $port = (int) ($serverConfig['port'] ?? 22);
        $tmp  = rtrim($serverConfig['tmp_path'] ?? '/tmp-pickup', '/');
        $pick = rtrim($serverConfig['pickup_path'] ?? '/pickup', '/');

        if (!$key || !file_exists($key)) {
            Log::channel('queue')->error("PmtaSync: SSH key not found for server '{$serverName}': {$key}");
            return;
        }

        $localFiles = glob($serverDir . '/email_*') ?: [];
        $localFiles = array_filter($localFiles, 'is_file');

        if (empty($localFiles)) {
            return;
        }

        $dest = $user . '@' . $host;
        $cm = $this->sshControlMaster($serverName);

        $sshRsh = sprintf(
            'ssh -i %s -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o IdentitiesOnly=yes %s -p %d -T',
            escapeshellarg($key),
            $cm,
            $port
        );

        // Step A: rsync the server subdirectory (NOT parent outgoing/) to remote tmp/
        $rsyncCmd = sprintf(
            'rsync -az --timeout=20 -e %s %s/ %s:%s/',
            escapeshellarg($sshRsh),
            escapeshellarg($serverDir),
            escapeshellarg($dest),
            escapeshellarg($tmp)
        );

        $rsyncCode = 1;
        $rsyncOut = [];
        for ($attempt = 1; $attempt <= 5 && $rsyncCode !== 0; $attempt++) {
            $rsyncOut = [];
            $this->executeCommand($rsyncCmd, $rsyncOut, $rsyncCode);
            if ($rsyncCode !== 0 && $attempt < 5) {
                Log::channel('queue')->warning("PmtaSync: rsync attempt {$attempt} failed for '{$serverName}', retrying...");
                sleep(2);
            }
        }

        if ($rsyncCode !== 0) {
            Log::channel('queue')->error("PmtaSync: rsync failed for '{$serverName}' after 5 attempts, exit_code={$rsyncCode}");
            return;
        }

        Log::channel('queue')->info("PmtaSync: rsync OK for '{$serverName}'");

        $this->doSshMvAndMark($serverName, $serverDir, $sentDir, $dest, $key, $cm, $port, $tmp, $pick);
    }

    /**
     * Sync legacy flat files to the remote server.
     */
    protected function processLegacyFiles(string $serverKey, array $files, string $outgoingDir, string $sentDir, array $serverConfig): void
    {
        $key  = $serverConfig['ssh_key'] ?? '';
        $host = $serverConfig['host'] ?? '';
        $user = $serverConfig['user'] ?? 'root';
        $port = (int) ($serverConfig['port'] ?? 22);
        $tmp  = rtrim($serverConfig['tmp_path'] ?? '/tmp-pickup', '/');
        $pick = rtrim($serverConfig['pickup_path'] ?? '/pickup', '/');

        if (!$key || !file_exists($key)) {
            Log::channel('queue')->error("PmtaSync: SSH key not found for legacy server '{$serverKey}': {$key}");
            return;
        }

        if (empty($files)) {
            return;
        }

        $dest = $user . '@' . $host;
        $cm = $this->sshControlMaster($host);

        $sshRsh = sprintf(
            'ssh -i %s -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o IdentitiesOnly=yes %s -p %d -T',
            escapeshellarg($key),
            $cm,
            $port
        );

        // rsync only flat files (exclude subdirectories)
        $rsyncCmd = sprintf(
            'rsync -az --timeout=20 --exclude="*/" -e %s %s/ %s:%s/',
            escapeshellarg($sshRsh),
            escapeshellarg($outgoingDir),
            escapeshellarg($dest),
            escapeshellarg($tmp)
        );

        $rsyncCode = 1;
        $rsyncOut = [];
        for ($attempt = 1; $attempt <= 5 && $rsyncCode !== 0; $attempt++) {
            $rsyncOut = [];
            $this->executeCommand($rsyncCmd, $rsyncOut, $rsyncCode);
            if ($rsyncCode !== 0 && $attempt < 5) {
                Log::channel('queue')->warning("PmtaSync: legacy rsync attempt {$attempt} failed for '{$serverKey}', retrying...");
                sleep(2);
            }
        }

        if ($rsyncCode !== 0) {
            Log::channel('queue')->error("PmtaSync: legacy rsync failed for '{$serverKey}' after 5 attempts");
            return;
        }

        $this->doSshMvAndMark($serverKey, $outgoingDir, $sentDir, $dest, $key, $cm, $port, $tmp, $pick);
    }

    /**
     * Verify files on remote, SSH mv tmp→pickup, mark email_logs as sent, move local files to sent/.
     */
    protected function doSshMvAndMark(
        string $label,
        string $localDir,
        string $localSentDir,
        string $dest,
        string $key,
        string $cm,
        int $port,
        string $tmp,
        string $pick
    ): void {
        $localFiles = glob($localDir . '/email_*') ?: [];
        $localFiles = array_filter($localFiles, 'is_file');
        $localBasenames = array_map('basename', $localFiles);

        if (empty($localBasenames)) {
            return;
        }

        $sshBase = sprintf(
            'ssh -i %s -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o IdentitiesOnly=yes %s -o ConnectTimeout=10 -p %d -T %s',
            escapeshellarg($key),
            $cm,
            $port,
            escapeshellarg($dest)
        );

        // Verify files exist on remote tmp/
        $checkCmd = $sshBase . ' ' . escapeshellarg("ls {$tmp}/ 2>/dev/null | grep '^email_'");
        $remoteFiles = [];
        $checkCode = 0;
        $this->executeCommand($checkCmd, $remoteFiles, $checkCode);
        $remoteFiles = array_filter(array_map('trim', $remoteFiles));

        if (empty($remoteFiles)) {
            Log::channel('queue')->warning("PmtaSync: rsync OK but no files verified on remote {$tmp} for '{$label}'");
            return;
        }

        Log::channel('queue')->info("PmtaSync: verified " . count($remoteFiles) . " files on remote for '{$label}'");

        // SSH mv from tmp/ to pickup/
        $oneLiner = 'set -e; TMP_DIR=' . escapeshellarg($tmp) . ' PICK_DIR=' . escapeshellarg($pick)
            . '; mkdir -p "$TMP_DIR" "$PICK_DIR"; for f in "$TMP_DIR"/email_*; do [ -f "$f" ] || continue; '
            . 'bn=$(basename "$f"); mv -- "$f" "$PICK_DIR/$bn"; printf "%s\n" "$bn"; done';

        $mvCmd = sprintf(
            'ssh -i %s -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o IdentitiesOnly=yes %s -o ConnectionAttempts=3 -o ConnectTimeout=8 -p %d -T %s bash -lc %s',
            escapeshellarg($key),
            $cm,
            $port,
            escapeshellarg($dest),
            escapeshellarg($oneLiner)
        );

        $mvCode = 1;
        $mvOut = [];
        for ($attempt = 1; $attempt <= 5 && $mvCode !== 0; $attempt++) {
            $mvOut = [];
            $this->executeCommand($mvCmd, $mvOut, $mvCode);
            if ($mvCode !== 0 && $attempt < 5) {
                Log::channel('queue')->warning("PmtaSync: ssh mv attempt {$attempt} failed for '{$label}', retrying...");
                sleep(2);
            }
        }

        if ($mvCode !== 0) {
            Log::channel('queue')->error("PmtaSync: ssh mv failed for '{$label}' after 5 attempts");
            return;
        }

        // Mark verified emails as sent, move local files to sent/
        $okCount = 0;
        foreach ($localBasenames as $bn) {
            if (!in_array($bn, $remoteFiles)) {
                Log::channel('queue')->warning("PmtaSync: skipping {$bn} — not verified on remote");
                continue;
            }

            if (!preg_match('/^email_(\d+)$/', $bn, $m)) {
                continue;
            }

            $id = (int) $m[1];
            $affected = EmailLog::where('id', $id)
                ->where('status', 'spooled')
                ->update(['status' => 'sent', 'error' => null]);

            if ($affected > 0) {
                $okCount++;

                AudienceUser::where('email', function ($query) use ($id) {
                    $query->select('recipient')->from('email_logs')->where('id', $id);
                })->whereNull('sent_at')->update(['sent_at' => now()]);
            }

            // Move local EML to sent/
            if (!is_dir($localSentDir)) {
                mkdir($localSentDir, 0775, true);
            }
            $src = $localDir . '/' . $bn;
            $dst = $localSentDir . '/' . $bn;
            if (is_file($src)) {
                @rename($src, $dst);
            }
        }

        Log::channel('queue')->info("PmtaSync: '{$label}' complete — {$okCount} marked sent");
    }

    /**
     * Wrapper around exec() for testability.
     */
    protected function executeCommand(string $cmd, array &$output, int &$exitCode): void
    {
        exec($cmd, $output, $exitCode);
    }

    protected function cleanOldSentFiles(string $sentDir): void
    {
        if (!is_dir($sentDir)) {
            return;
        }

        $cutoff = time() - (7 * 24 * 3600);

        // Clean flat files
        foreach (glob($sentDir . '/email_*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
                Log::channel('queue')->info("PmtaSync: deleted old spool file {$file}");
            }
        }

        // Clean files in server subdirectories
        foreach (glob($sentDir . '/*', GLOB_ONLYDIR) ?: [] as $subDir) {
            foreach (glob($subDir . '/email_*') ?: [] as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    @unlink($file);
                    Log::channel('queue')->info("PmtaSync: deleted old spool file {$file}");
                }
            }
        }
    }
}
