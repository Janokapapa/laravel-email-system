<?php

namespace JanDev\EmailSystem\Support;

use JanDev\EmailSystem\Models\EmailLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

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
    public function writeEml(EmailLog $emailLog, ?string $unsubscribeUrl = null): string
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
        $messageId = '<' . md5(uniqid()) . '@' . $fromDomain . '>';
        $date = date('r');

        $subject = '=?UTF-8?B?' . base64_encode($emailLog->subject) . '?=';
        $fromHeader = $fromName ? "{$fromName} <{$fromAddress}>" : $fromAddress;

        $isPlainText = ($emailLog->content_type ?? 'html') === 'text';

        // List-Unsubscribe headers (RFC 2369 / RFC 8058) — improves deliverability
        $unsubHeaders = '';
        if ($unsubscribeUrl) {
            $unsubHeaders = "List-Unsubscribe: <{$unsubscribeUrl}>\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\n";
        }

        if ($isPlainText) {
            // Plain text mode: single text/plain part, no HTML wrapping
            $rawText = (string) $emailLog->message;

            // Replace unsubscribe placeholders as plain text URLs
            if ($unsubscribeUrl) {
                $rawText = preg_replace('/\{\{unsubscribe=(.+?)\}\}/', '$1: ' . $unsubscribeUrl, $rawText);
                $rawText = str_replace('{{unsubscribe_url}}', $unsubscribeUrl, $rawText);
            }

            $textBody = quoted_printable_encode($rawText);

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
{$unsubHeaders}MIME-Version: 1.0
Content-Type: text/plain; charset="utf-8"
Content-Transfer-Encoding: quoted-printable

{$textBody}
EOT;
        } else {
            // HTML mode: multipart/alternative with text/plain + text/html
            $boundary = 'b1_' . md5(uniqid((string) microtime(), true));
            $rawHtml = (string) $emailLog->message;

            // Replace unsubscribe placeholders in the HTML
            if ($unsubscribeUrl) {
                $rawHtml = self::replaceUnsubscribeLinks($rawHtml, $unsubscribeUrl);
            }

            // Rewrite links for click tracking (if enabled for this sender)
            if ($this->senderConfig['track_clicks'] ?? true) {
                $rawHtml = self::rewriteLinksForTracking($rawHtml, $emailLog->id, $unsubscribeUrl);
            }

            // Wrap in full HTML document if the template is just a fragment (no <html> tag).
            if (stripos($rawHtml, '<html') === false) {
                $bodyBg = '';
                if (preg_match('/background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|[a-z]+)/i', $rawHtml, $m)) {
                    $bodyBg = 'background-color:' . $m[1] . ';';
                }

                $rawHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
                    . '<body style="margin:0;padding:0;' . $bodyBg . '">' . $rawHtml . '</body></html>';
            }

            // Inject open tracking pixel before </body> if enabled for this sender
            if ($this->senderConfig['track_opens'] ?? false) {
                $pixelUrl = URL::signedRoute('email-system.track.open', ['log_id' => $emailLog->id]);
                $pixel = '<img src="' . htmlspecialchars($pixelUrl) . '" alt="" width="1" height="1" style="display:none;" />';
                $rawHtml = str_ireplace('</body>', $pixel . '</body>', $rawHtml);
            }

            $htmlBody = quoted_printable_encode($rawHtml);
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
{$unsubHeaders}MIME-Version: 1.0
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
        }

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

    /**
     * Rewrite all trackable links in the HTML to go through the click tracking endpoint.
     * Skips: mailto:, #anchors, unsubscribe URLs, empty hrefs, and the tracking domain itself.
     */
    public static function rewriteLinksForTracking(string $html, int $logId, ?string $unsubscribeUrl): string
    {
        $trackingDomain = parse_url(config('app.url'), PHP_URL_HOST);

        return preg_replace_callback(
            '/<a\b([^>]*?)href\s*=\s*"([^"]*)"([^>]*?)>/is',
            function ($match) use ($logId, $unsubscribeUrl, $trackingDomain) {
                $href = $match[2];

                // Skip non-trackable links
                if (
                    $href === '' ||
                    $href === '#' ||
                    str_starts_with($href, 'mailto:') ||
                    str_starts_with($href, 'tel:') ||
                    ($unsubscribeUrl && $href === $unsubscribeUrl) ||
                    (stripos($href, 'unsubscribe') !== false) ||
                    ($trackingDomain && str_contains($href, $trackingDomain))
                ) {
                    return $match[0];
                }

                $trackingUrl = URL::signedRoute('email-system.track.click', [
                    'log_id' => $logId,
                    'url' => $href,
                ]);

                return '<a' . $match[1] . 'href="' . htmlspecialchars($trackingUrl) . '"' . $match[3] . '>';
            },
            $html
        );
    }

    /**
     * Replace unsubscribe placeholders in the HTML.
     *
     * Handles:
     * - {{unsubscribe=Link Text}} → <a href="URL">Link Text</a>
     * - {{unsubscribe_url}} → raw URL (backward compat)
     * - href="#" in anchors containing "unsubscribe" text (backward compat)
     */
    public static function replaceUnsubscribeLinks(string $html, string $url): string
    {
        // Replace {{unsubscribe=Link Text}} placeholder with anchor tag
        $escapedUrl = htmlspecialchars($url);
        $html = preg_replace_callback(
            '/\{\{unsubscribe=(.+?)\}\}/',
            fn ($m) => '<a href="' . $escapedUrl . '" style="color:inherit;text-decoration:underline;">' . $m[1] . '</a>',
            $html
        );

        // Replace {{unsubscribe_url}} placeholder (backward compat)
        $html = str_replace('{{unsubscribe_url}}', $url, $html);

        // Replace href="#" in anchor tags that contain "unsubscribe" text (backward compat)
        $html = preg_replace_callback(
            '/<a\b([^>]*?)href\s*=\s*"#"([^>]*?)>(.*?)<\/a>/is',
            function ($match) use ($url) {
                if (stripos($match[3], 'unsubscribe') !== false || stripos($match[3], 'leiratkoz') !== false) {
                    return '<a' . $match[1] . 'href="' . htmlspecialchars($url) . '"' . $match[2] . '>' . $match[3] . '</a>';
                }
                return $match[0];
            },
            $html
        );

        return $html;
    }
}
