<?php

namespace JanDev\EmailSystem\Support;

use JanDev\EmailSystem\Models\EmailLog;
use Illuminate\Support\Facades\Log;

class PmtaSpooler
{
    private readonly string $localBaseDir;

    /**
     * @param array       $senderConfig  Sender fields (from_address, from_name, reply_to, pmta_virtual_mta)
     * @param string|null $localBaseDir  Base dir for outgoing/sent/failed (defaults to storage/app/mailspool)
     * @param array|null  $serverConfig  Resolved PMTA server config (host, virtual_mta, etc.)
     * @param string|null $serverName    When set, EML goes to outgoing/{serverName}/email_{id} (subdirectory mode)
     */
    public function __construct(
        private readonly array $senderConfig,
        ?string $localBaseDir = null,
        private readonly ?array $serverConfig = null,
        private readonly ?string $serverName = null,
    ) {
        $this->localBaseDir = $localBaseDir ?? storage_path('app/mailspool');
    }

    public function ensureDirs(): void
    {
        foreach (['outgoing', 'sent', 'failed'] as $dir) {
            $path = $this->localBaseDir . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }

        // Ensure server subdirectory when routing is active
        if ($this->serverName !== null) {
            $serverDir = $this->localBaseDir . '/outgoing/' . $this->serverName;
            if (!is_dir($serverDir)) {
                mkdir($serverDir, 0775, true);
            }
        }

        // Clean up stale .tmp files (orphaned from interrupted writes, >5 min old)
        $outgoingDir = $this->serverName !== null
            ? $this->localBaseDir . '/outgoing/' . $this->serverName
            : $this->localBaseDir . '/outgoing';
        foreach (glob($outgoingDir . '/*.tmp') ?: [] as $tmp) {
            if (filemtime($tmp) < time() - 300) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Build RFC-compliant EML and write atomically.
     *
     * When serverName is set: writes to outgoing/{serverName}/email_{id} (domain routing active).
     * When serverName is null: writes to outgoing/email_{id} (flat, backward compat).
     *
     * Returns the path to the written file.
     * Idempotent: if file already exists, returns the path without rewriting.
     */
    public function writeEml(EmailLog $emailLog): string
    {
        $this->ensureDirs();

        $fromAddress = $this->senderConfig['from_address'];
        $fromName = $this->senderConfig['from_name'] ?? '';
        $replyTo = $this->senderConfig['reply_to'] ?? $fromAddress;

        // Virtual MTA priority: sender override (if non-empty) > server config > fallback 'all'
        $virtualMta = (isset($this->senderConfig['pmta_virtual_mta']) && $this->senderConfig['pmta_virtual_mta'] !== '')
            ? $this->senderConfig['pmta_virtual_mta']
            : ($this->serverConfig['virtual_mta'] ?? 'all');

        $fromDomain = substr(strrchr($fromAddress, '@'), 1) ?: 'localhost';
        $boundary = 'b1_' . md5(uniqid((string) microtime(), true));
        $messageId = '<' . md5(uniqid()) . '@' . $fromDomain . '>';
        $date = date('r');

        $subject = '=?UTF-8?B?' . base64_encode($emailLog->subject) . '?=';
        $fromHeader = $fromName ? "{$fromName} <{$fromAddress}>" : $fromAddress;

        $htmlBody = quoted_printable_encode((string) $emailLog->message);
        $textBody = quoted_printable_encode(trim(strip_tags((string) $emailLog->message)));

        $eml = <<<EOT
x-sender: {$fromAddress}
x-receiver: {$emailLog->recipient}
x-virtual-mta: {$virtualMta}
Date: {$date}
To: <{$emailLog->recipient}>
From: {$fromHeader}
Reply-To: <{$replyTo}>
Subject: {$subject}
Message-ID: {$messageId}
X-Priority: 3
MIME-Version: 1.0
Content-Type: multipart/alternative; boundary="{$boundary}"

--{$boundary}
Content-Type: text/plain; charset="utf-8"
Content-Transfer-Encoding: quoted-printable

{$textBody}

--{$boundary}
Content-Type: text/html; charset="utf-8"
Content-Transfer-Encoding: quoted-printable

{$htmlBody}

--{$boundary}--
EOT;

        $baseName = 'email_' . $emailLog->id;

        // Subdirectory mode when serverName is set (domain routing active)
        $outgoingDir = $this->serverName !== null
            ? $this->localBaseDir . '/outgoing/' . $this->serverName
            : $this->localBaseDir . '/outgoing';

        $final = $outgoingDir . '/' . $baseName;

        // Idempotent: if already exists, return without rewriting
        if (is_file($final)) {
            Log::channel('queue')->info("PmtaSpooler: spool exists (skipped rewrite): {$final}");
            return $final;
        }

        // Atomic write: write to .tmp, then rename
        $tmp = $final . '.tmp';
        if (@file_put_contents($tmp, $eml) === false) {
            throw new \RuntimeException("PmtaSpooler: failed to write spool tmp: {$tmp}");
        }
        if (!@rename($tmp, $final)) {
            @unlink($tmp);
            throw new \RuntimeException("PmtaSpooler: failed to finalize spool file: {$final}");
        }

        Log::channel('queue')->info("PmtaSpooler: spooled {$final}");

        return $final;
    }
}
