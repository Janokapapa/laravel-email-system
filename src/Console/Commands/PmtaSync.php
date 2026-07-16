<?php

namespace JanDev\EmailSystem\Console\Commands;

use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\EmailSendingDomain;
use JanDev\EmailSystem\Support\PmtaSpooler;
use JanDev\EmailSystem\Support\SenderResolver;
use JanDev\EmailSystem\Support\WarmupLimiter;
use JanDev\EmailSystem\Support\ProviderResolver;
use JanDev\EmailSystem\Support\ProviderRewarmLimiter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
        $spoolBase = config('email-system.pmta.spool_path') ?: storage_path('app/mailspool');

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
        } else {
            Log::channel('queue')->info("PmtaSync: found {$spooledCount} spooled emails");

            // Track EML generation count per server to enforce batch_size
            $serverBatchCount = [];

            // Per-sending-domain warmup daily-cap limiter. Holds running
            // counters for this run (seeded from today's already-sent counts).
            $warmup = new WarmupLimiter();

            // Per-domain, per-provider re-warm limiter (cap + engaged-only).
            $rewarm = new ProviderRewarmLimiter();

            // Per-run cache of sending-domain records (provider-block policy).
            // Keyed by lowercased domain; value is EmailSendingDomain|null.
            $domainRecords = [];

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

                // Honor per-email (campaign-level) From / display-name / Reply-To
                // overrides on top of the sender definition, mirroring
                // SendQueuedEmail. Without this the actual From + DKIM domain would
                // come from the definition, not the domain the campaign selected —
                // so a campaign switched to another From domain would still go out
                // from the definition's domain.
                $senderConfig = SenderResolver::applyEmailOverrides(
                    $senderConfig,
                    $emailLog->sender,
                    $emailLog->sender_display_name,
                    $emailLog->reply_to
                );

                // Resolve target server: routing profile → pmta_server fallback → legacy global routing
                $resolvedServer = SenderResolver::resolveServerForRecipient($emailLog->recipient, $senderConfig);

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

                // Check if EML already exists (idempotent).
                // Look across ALL outgoing/*/ subdirectories AND the flat outgoing/ —
                // failover may have moved the file from the originally-resolved server
                // to a fallback server's directory. Without this, PHASE 1 would
                // regenerate the EML with a fresh Message-ID and the recipient would
                // receive the same campaign twice.
                $basename = 'email_' . $emailLog->id;
                $anyExisting = glob($spoolBase . '/outgoing/*/' . $basename) ?: [];
                $flatPath = $spoolBase . '/outgoing/' . $basename;
                if (!empty($anyExisting) || is_file($flatPath)) {
                    continue; // Already spooled (in target or failover dir)
                }

                $fromDomain = $this->extractSendingDomain($emailLog, $senderConfig);

                // Per-domain provider policy: hard block, or a re-warm cap.
                if ($fromDomain !== '') {
                    $key = strtolower($fromDomain);
                    if (!array_key_exists($key, $domainRecords)) {
                        $domainRecords[$key] = EmailSendingDomain::where('domain', $key)->first();
                    }
                    $record = $domainRecords[$key];
                    if ($record) {
                        $provider = ProviderResolver::resolve($emailLog->recipient);

                        // (a) Hard block: defer — leave 'spooled', never hand to PMTA.
                        if ($record->blocksProvider($provider)) {
                            continue;
                        }

                        // (b) Re-warm cap: for a provider with a recovery policy,
                        // only engaged recipients pass, and only up to daily_cap/day.
                        // Everyone else is deferred (left 'spooled') for a later day.
                        $policy = $record->providerPolicy($provider);
                        if ($policy !== null
                            && !$rewarm->allow($fromDomain, $emailLog->recipient, $provider, $policy)) {
                            continue;
                        }
                    }
                }

                // Warmup daily-cap: defer (leave spooled) once the sending
                // domain hit its per-day volume (or iCloud sub-cap) for today.
                // A deferred email stays 'spooled' and the next run/day picks it up.
                if ($fromDomain !== '' && !$warmup->allow($fromDomain, $emailLog->recipient)) {
                    continue;
                }

                // Generate unsubscribe URL for this recipient
                $unsubscribeUrl = $this->generateUnsubscribeUrl($emailLog);

                // Write EML file
                $spooler = new PmtaSpooler($senderConfig, $spoolBase, $resolvedServer, $serverName);
                try {
                    $spooler->writeEml($emailLog, $unsubscribeUrl);
                } catch (\RuntimeException $e) {
                    Log::channel('queue')->error("PmtaSync: failed to write EML for email {$emailLog->id}: " . $e->getMessage());
                }
            }
        }

        // === PHASE 2: Sync per-server subdirectories (always runs — handles failover files too) ===
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

        $failedServers = [];

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

            $success = $this->processServer($serverName, $serverDir, $sentDir, $serverConfig);

            // Track failed servers that still have pending files
            if (!$success) {
                $pendingFiles = glob($serverDir . '/email_*') ?: [];
                $pendingFiles = array_filter($pendingFiles, 'is_file');
                if (!empty($pendingFiles)) {
                    $failedServers[] = $serverName;
                }
            }
        }

        // === Failover: move files from failed servers to fallback ===
        $this->handleFailover($spoolBase, $failedServers);
    }

    /**
     * Move pending EML files from failed servers to their configured fallback.
     * Rewrites headers to match the fallback server:
     * - x-virtual-mta → fallback server's virtual_mta
     * - x-sender → VERP bounce address rebuilt with fallback server's bounce_domain
     *   (if fallback has no bounce_domain, uses plain from address extracted from From header)
     */
    protected function handleFailover(string $spoolBase, array $failedServers): void
    {
        if (empty($failedServers)) {
            return;
        }

        $failoverMap = SenderResolver::pmtaFailoverMap();
        if (empty($failoverMap)) {
            Log::channel('queue')->warning('PmtaSync: servers failed (' . implode(', ', $failedServers) . ') but no failover map configured');
            return;
        }

        $outgoing = $spoolBase . '/outgoing';

        foreach ($failedServers as $failedName) {
            $fallbackName = $failoverMap[$failedName] ?? null;

            // Don't failover to a server that also failed in this cycle
            if (!$fallbackName || in_array($fallbackName, $failedServers)) {
                Log::channel('queue')->warning("PmtaSync: no viable fallback for '{$failedName}'"
                    . ($fallbackName ? " (fallback '{$fallbackName}' also failed)" : ''));
                continue;
            }

            $fallbackConfig = SenderResolver::pmtaServer($fallbackName);
            if (!$fallbackConfig) {
                Log::channel('queue')->warning("PmtaSync: fallback server '{$fallbackName}' config not found");
                continue;
            }

            $srcDir = $outgoing . '/' . $failedName;
            $dstDir = $outgoing . '/' . $fallbackName;
            if (!is_dir($dstDir)) {
                mkdir($dstDir, 0775, true);
            }

            $files = glob($srcDir . '/email_*') ?: [];
            $files = array_filter($files, 'is_file');
            $fallbackVmta = $fallbackConfig['virtual_mta'] ?? 'all';
            $fallbackBounceDomain = $fallbackConfig['bounce_domain'] ?? '';
            $movedCount = 0;

            foreach ($files as $file) {
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }

                // Rewrite x-virtual-mta header to match fallback server
                $content = preg_replace(
                    '/^x-virtual-mta: .+$/m',
                    'x-virtual-mta: ' . $fallbackVmta,
                    $content,
                    1
                );

                // Rewrite x-sender (VERP bounce address) to match fallback server's bounce_domain
                $content = $this->rewriteVerpSender($content, $fallbackBounceDomain);

                file_put_contents($file, $content);

                if (@rename($file, $dstDir . '/' . basename($file))) {
                    $movedCount++;
                }
            }

            if ($movedCount > 0) {
                Log::channel('queue')->info("PmtaSync: failover {$failedName} → {$fallbackName}, moved {$movedCount} files");
            }
        }
    }

    /**
     * Rewrite the x-sender VERP bounce address for a new bounce_domain.
     *
     * VERP format: bounce-{id}-{fromDomain}@{bounceDomain}
     * If fallback has a bounce_domain: rebuild VERP with new domain.
     * If fallback has no bounce_domain: extract From address and use that as plain sender.
     */
    protected function rewriteVerpSender(string $content, string $fallbackBounceDomain): string
    {
        if ($fallbackBounceDomain !== '') {
            // Rebuild VERP with fallback's bounce domain, preserving the id and from-domain parts
            return preg_replace(
                '/^x-sender: bounce-(\d+)-([^@]+)@.+$/m',
                'x-sender: bounce-$1-$2@' . $fallbackBounceDomain,
                $content,
                1
            );
        }

        // Fallback has no bounce_domain → use plain from address (no VERP)
        // Extract from address from the From header
        $fromAddress = null;
        if (preg_match('/^From: .*?<([^>]+)>/m', $content, $m)) {
            $fromAddress = $m[1];
        } elseif (preg_match('/^From: (\S+@\S+)/m', $content, $m)) {
            $fromAddress = $m[1];
        }

        if ($fromAddress) {
            return preg_replace(
                '/^x-sender: .+$/m',
                'x-sender: ' . $fromAddress,
                $content,
                1
            );
        }

        return $content;
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
    protected function processServer(string $serverName, string $serverDir, string $sentDir, array $serverConfig): bool
    {
        $key  = $serverConfig['ssh_key'] ?? '';
        $host = $serverConfig['host'] ?? '';
        $user = $serverConfig['user'] ?? 'root';
        $port = (int) ($serverConfig['port'] ?? 22);
        $tmp  = rtrim($serverConfig['tmp_path'] ?? '/tmp-pickup', '/');
        $pick = rtrim($serverConfig['pickup_path'] ?? '/pickup', '/');

        if (!$key || !file_exists($key)) {
            Log::channel('queue')->error("PmtaSync: SSH key not found for server '{$serverName}': {$key}");
            return false;
        }

        $localFiles = glob($serverDir . '/email_*') ?: [];
        $localFiles = array_filter($localFiles, 'is_file');

        if (empty($localFiles)) {
            return true; // No files = nothing to do, not a failure
        }

        $dest = $user . '@' . $host;
        $cm = $this->sshControlMaster($serverName);

        $sshRsh = sprintf(
            'ssh -i %s -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o IdentitiesOnly=yes %s -p %d -T',
            escapeshellarg($key),
            $cm,
            $port
        );

        // Step 0: Clean orphaned files from remote tmp/ that don't match local outgoing.
        // Prevents duplicates from partial rsync transfers of previous failed runs.
        $this->cleanOrphanedRemoteFiles($serverName, $localFiles, $dest, $key, $cm, $port, $tmp);

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
            return false;
        }

        Log::channel('queue')->info("PmtaSync: rsync OK for '{$serverName}'");

        $this->doSshMvAndMark($serverName, $serverDir, $sentDir, $dest, $key, $cm, $port, $tmp, $pick);

        return true;
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
                ->update(['status' => 'sent', 'error' => null, 'sent_at' => now()]);

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
     * Remove orphaned email_* files from remote tmp/ that don't exist in local outgoing.
     * These are leftovers from partial rsync transfers of previous failed runs.
     * Without this cleanup, doSshMvAndMark would move them to pickup → duplicate sends.
     */
    protected function cleanOrphanedRemoteFiles(
        string $serverName,
        array $localFiles,
        string $dest,
        string $key,
        string $cm,
        int $port,
        string $tmp
    ): void {
        $sshBase = sprintf(
            'ssh -i %s -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o IdentitiesOnly=yes %s -o ConnectTimeout=10 -p %d -T %s',
            escapeshellarg($key),
            $cm,
            $port,
            escapeshellarg($dest)
        );

        // List remote tmp/ files
        $listCmd = $sshBase . ' ' . escapeshellarg("ls {$tmp}/ 2>/dev/null | grep '^email_'");
        $remoteFiles = [];
        $listCode = 0;
        $this->executeCommand($listCmd, $remoteFiles, $listCode);
        $remoteFiles = array_filter(array_map('trim', $remoteFiles));

        if (empty($remoteFiles)) {
            return;
        }

        // Build set of local basenames we're about to sync
        $localBasenames = array_flip(array_map('basename', $localFiles));

        // Find orphans: files on remote that we don't have locally
        $orphans = array_filter($remoteFiles, fn ($f) => !isset($localBasenames[$f]));

        if (empty($orphans)) {
            return;
        }

        // Delete orphaned files from remote tmp/
        $rmList = implode(' ', array_map(fn ($f) => escapeshellarg("{$tmp}/{$f}"), $orphans));
        $rmCmd = $sshBase . ' ' . escapeshellarg("rm -f {$rmList}");
        $rmOut = [];
        $rmCode = 0;
        $this->executeCommand($rmCmd, $rmOut, $rmCode);

        Log::channel('queue')->info(
            "PmtaSync: cleaned " . count($orphans) . " orphaned files from remote {$tmp}/ for '{$serverName}'"
        );
    }

    /**
     * Resolve the sending (From/DKIM) domain for a spooled email.
     * Prefers the per-email From address (email_logs.sender), falling back to
     * the sender config's from_address. Returns lowercased domain, or ''.
     */
    protected function extractSendingDomain(EmailLog $emailLog, array $senderConfig): string
    {
        $from = $emailLog->sender ?: ($senderConfig['from_address'] ?? '');
        $at = strrpos((string) $from, '@');
        if ($at === false) {
            return '';
        }
        return strtolower(trim(substr($from, $at + 1)));
    }

    /**
     * Wrapper around exec() for testability.
     */
    protected function executeCommand(string $cmd, array &$output, int &$exitCode): void
    {
        exec($cmd, $output, $exitCode);
    }

    protected function generateUnsubscribeUrl(EmailLog $emailLog): string
    {
        return DB::transaction(function () use ($emailLog) {
            $token = bin2hex(random_bytes(16));

            $audienceUsers = AudienceUser::where('email', $emailLog->recipient)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get();

            if ($audienceUsers->isNotEmpty()) {
                foreach ($audienceUsers as $audienceUser) {
                    $audienceUser->update(['unsubscribe_token' => $token]);
                }
            }

            return route('email-system.unsubscribe', [
                'email' => $emailLog->recipient,
                'token' => $token,
                'log_id' => $emailLog->id,
            ]);
        });
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
