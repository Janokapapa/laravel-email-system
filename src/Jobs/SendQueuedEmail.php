<?php

namespace JanDev\EmailSystem\Jobs;

use Exception;
use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Mail\NewsletterMail;
use JanDev\EmailSystem\Support\PmtaSpooler;
use JanDev\EmailSystem\Support\SenderResolver;
use JanDev\EmailSystem\Support\ProviderResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mailgun\Mailgun;

class SendQueuedEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        protected EmailLog $emailLog
    ) {}

    public function handle(): void
    {
        $this->emailLog->refresh();

        if ($this->emailLog->status === 'sent') {
            Log::channel('queue')->warning('DUPLICATE PREVENTED: Email already sent', [
                'email_log_id' => $this->emailLog->id,
                'recipient' => $this->emailLog->recipient,
                'subject' => $this->emailLog->subject,
                'mailgun_message_id' => $this->emailLog->mailgun_message_id,
                'attempt' => $this->attempts(),
            ]);
            return;
        }

        $senderConfig = $this->emailLog->sender_name
            ? SenderResolver::get($this->emailLog->sender_name)
            : null;

        // Override sender config with campaign-specific values from EmailLog
        if ($senderConfig) {
            if ($this->emailLog->sender) {
                $senderConfig['from_address'] = $this->emailLog->sender;
            }
            if ($this->emailLog->sender_display_name) {
                $senderConfig['from_name'] = $this->emailLog->sender_display_name;
            }
            if ($this->emailLog->reply_to) {
                $senderConfig['reply_to'] = $this->emailLog->reply_to;
            }
        }

        $senderType = $senderConfig['type'] ?? config('email-system.driver', 'smtp');

        try {
            if ($senderType === 'pmta') {
                $this->sendViaPmta($senderConfig);
            } elseif ($senderType === 'mailgun') {
                $this->sendViaMailgun($senderConfig);
            } else {
                $this->sendViaSmtp($senderConfig);
            }

            $this->refreshCampaignStatus();
        } catch (Exception $e) {
            $this->emailLog->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            $this->refreshCampaignStatus();
            Log::channel('queue')->error('SendQueuedEmail error: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function sendViaPmta(?array $senderConfig): void
    {
        if (!$senderConfig) {
            Log::channel('queue')->error('SendQueuedEmail: PMTA sender config is null', [
                'email_log_id' => $this->emailLog->id,
            ]);
            return;
        }

        // Resolve server via sender's routing profile, pmta_server fallback, or legacy global routing
        $resolvedServer = SenderResolver::resolveServerForRecipient($this->emailLog->recipient, $senderConfig);

        $serverName = $resolvedServer['name'] ?? null;

        $unsubscribeUrl = $this->generateUnsubscribeUrl();

        $spooler = new PmtaSpooler($senderConfig, null, $resolvedServer, $serverName);
        $spooler->writeEml($this->emailLog, $unsubscribeUrl);

        $this->emailLog->update([
            'status' => 'spooled',
            'error' => null,
        ]);

        Log::channel('queue')->info('SendQueuedEmail: spooled via PMTA for: ' . $this->emailLog->recipient, [
            'server' => $serverName,
        ]);
    }

    protected function sendViaSmtp(?array $senderConfig = null): void
    {
        DB::transaction(function () use ($senderConfig) {
            $unsubscribeUrl = $this->generateUnsubscribeUrl();
            $isPlainText = ($this->emailLog->content_type ?? 'html') === 'text';

            // Process message in-memory (not persisted)
            $originalMessage = $this->emailLog->message;
            $processed = (string) $originalMessage;

            if ($isPlainText) {
                // Plain text: replace unsubscribe placeholders as URLs
                $processed = preg_replace('/\{\{unsubscribe=(.+?)\}\}/', '$1: ' . $unsubscribeUrl, $processed);
                $processed = str_replace('{{unsubscribe_url}}', $unsubscribeUrl, $processed);
            } else {
                // HTML: full processing
                $processed = PmtaSpooler::resolveRelativeUrls($processed);
                $processed = PmtaSpooler::replaceUnsubscribeLinks($processed, $unsubscribeUrl);
                if ($senderConfig['track_clicks'] ?? true) {
                    $processed = PmtaSpooler::rewriteLinksForTracking($processed, $this->emailLog->id, $unsubscribeUrl);
                }
            }

            $this->emailLog->message = $processed;

            $fullConfig = SenderResolver::resolveFullSmtpConfig($senderConfig ?? []);

            $mailerKey = null;
            if (!empty($fullConfig['host'])) {
                // Dynamic mailer built from smtp_servers setting in DB
                $mailerKey = '_smtp_' . ($fullConfig['name'] ?? 'dynamic');
                config(["mail.mailers.{$mailerKey}" => [
                    'transport'  => 'smtp',
                    'host'       => $fullConfig['host'],
                    'port'       => (int) ($fullConfig['port'] ?? 587),
                    'encryption' => $fullConfig['encryption'] ?? 'tls',
                    'username'   => $fullConfig['username'] ?? null,
                    'password'   => $fullConfig['password'] ?? null,
                ]]);
            }

            $mailer = $mailerKey ?? ($fullConfig['smtp_mailer'] ?? config('email-system.smtp.mailer', 'smtp'));

            try {
                Mail::mailer($mailer)->send(new NewsletterMail(
                    $this->emailLog,
                    $unsubscribeUrl,
                    $senderConfig
                ));
            } finally {
                // Clear dynamic mailer config to prevent leak between queue worker jobs
                if ($mailerKey !== null) {
                    config(["mail.mailers.{$mailerKey}" => null]);
                }
            }

            // Restore original message
            $this->emailLog->message = $originalMessage;

            $this->emailLog->update([
                'status' => 'sent',
                'error' => null,
            ]);

            AudienceUser::where('email', $this->emailLog->recipient)
                ->whereNull('sent_at')
                ->update(['sent_at' => now()]);

            Log::channel('queue')->info('Email sent via SMTP to: ' . $this->emailLog->recipient);
        });
    }

    protected function sendViaMailgun(?array $senderConfig = null): void
    {
        DB::transaction(function () use ($senderConfig) {
            $unsubscribeUrl = $this->generateUnsubscribeUrl();
            $isPlainText = ($this->emailLog->content_type ?? 'html') === 'text';

            $mgClient = Mailgun::create(
                $senderConfig['mailgun_secret'] ?? config('email-system.mailgun.secret'),
                $senderConfig['mailgun_endpoint'] ?? config('email-system.mailgun.endpoint', 'https://api.eu.mailgun.net')
            );

            $domain = $senderConfig['mailgun_domain'] ?? config('email-system.mailgun.domain');

            $messageContent = (string) $this->emailLog->message;

            $fromAddress = $senderConfig['from_address'] ?? config('email-system.from.address');
            $fromName = $senderConfig['from_name'] ?? config('email-system.from.name');
            $replyTo = $senderConfig['reply_to'] ?? config('email-system.reply_to');

            if ($isPlainText) {
                // Plain text: replace unsubscribe placeholders as URLs
                $messageContent = preg_replace('/\{\{unsubscribe=(.+?)\}\}/', '$1: ' . $unsubscribeUrl, $messageContent);
                $messageContent = str_replace('{{unsubscribe_url}}', $unsubscribeUrl, $messageContent);

                $params = [
                    'from' => "{$fromName} <{$fromAddress}>",
                    'to' => $this->emailLog->recipient,
                    'subject' => $this->emailLog->subject,
                    'text' => $messageContent,
                ];
            } else {
                // HTML: full processing with layout
                $messageContent = PmtaSpooler::resolveRelativeUrls($messageContent);
                $messageContent = PmtaSpooler::replaceUnsubscribeLinks($messageContent, $unsubscribeUrl);
                if ($senderConfig['track_clicks'] ?? true) {
                    $messageContent = PmtaSpooler::rewriteLinksForTracking(
                        $messageContent,
                        $this->emailLog->id,
                        $unsubscribeUrl
                    );
                }

                $htmlContent = view('email-system::newsletter', [
                    'emailLog' => $this->emailLog,
                    'subject' => $this->emailLog->subject,
                    'messageContent' => $messageContent,
                    'unsubscribeUrl' => $unsubscribeUrl,
                    'trackOpens' => $senderConfig['track_opens'] ?? false,
                ])->render();

                $params = [
                    'from' => "{$fromName} <{$fromAddress}>",
                    'to' => $this->emailLog->recipient,
                    'subject' => $this->emailLog->subject,
                    'html' => $htmlContent,
                ];
            }

            if ($replyTo) {
                $params['h:Reply-To'] = $replyTo;
            }

            $response = $mgClient->messages()->send($domain, $params);

            if ($response->getId()) {
                $messageId = trim($response->getId(), '<>');

                $this->emailLog->update([
                    'status' => 'sent',
                    'error' => null,
                    'mailgun_message_id' => $messageId,
                ]);

                AudienceUser::where('email', $this->emailLog->recipient)
                    ->whereNull('sent_at')
                    ->update(['sent_at' => now()]);

                Log::channel('queue')->info('Email sent via Mailgun. Message ID: ' . $messageId);
            } else {
                $this->emailLog->update([
                    'status' => 'failed',
                    'error' => json_encode($response),
                ]);
            }
        });
    }

    protected function generateUnsubscribeUrl(): string
    {
        return DB::transaction(function () {
            $token = bin2hex(random_bytes(16));

            $audienceUsers = AudienceUser::where('email', $this->emailLog->recipient)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get();

            if ($audienceUsers->isNotEmpty()) {
                foreach ($audienceUsers as $audienceUser) {
                    $audienceUser->update(['unsubscribe_token' => $token]);
                }
            }

            return route('email-system.unsubscribe', [
                'email' => $this->emailLog->recipient,
                'token' => $token,
                'log_id' => $this->emailLog->id,
            ]);
        });
    }

    protected function refreshCampaignStatus(): void
    {
        if (!$this->emailLog->campaign_id) {
            return;
        }

        $campaign = Campaign::find($this->emailLog->campaign_id);
        if (!$campaign || in_array($campaign->status, ['sent', 'new'])) {
            return;
        }

        $campaign->refreshCounts();
        $campaign->updateStatusFromCounts();
    }

    public function failed(\Throwable $exception): void
    {
        $this->emailLog->update([
            'status' => 'failed',
            'error' => 'Final failure: ' . $exception->getMessage(),
        ]);

        $this->refreshCampaignStatus();
    }
}
