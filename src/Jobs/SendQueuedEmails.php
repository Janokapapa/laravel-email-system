<?php

namespace JanDev\EmailSystem\Jobs;

use Exception;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\JobTracker;
use JanDev\EmailSystem\Mail\NewsletterMail;
use JanDev\EmailSystem\Support\SenderResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mailgun\Mailgun;

use function JanDev\EmailSystem\resolve_callback;

class SendQueuedEmails implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $startTime = microtime(true);
        $defaultDriver = config('email-system.driver', 'smtp');

        $maxPerRun = config('email-system.send.max_per_run', 100);
        $delaySeconds = config('email-system.send.delay_seconds', 1);

        // Only process 'queued' emails — 'spooled' (PMTA) are handled by PmtaSync command
        $emails = EmailLog::where('status', 'queued')
            ->where('created_at', '>=', now()->subDay())
            ->take($maxPerRun)
            ->get();

        if ($emails->isEmpty()) {
            Log::channel('queue')->info('SendQueuedEmails: No queued emails found');

            // Mark any running send trackers as completed (queue drained)
            JobTracker::where('type', 'email_send')
                ->where('status', 'running')
                ->each(fn ($t) => $t->markCompleted());

            if (Cache::get('email_system_queue_active')) {
                $this->sendCompletionNotification();
                Cache::forget('email_system_queue_active');
            }
            return;
        }

        Cache::put('email_system_queue_active', true, now()->addHours(24));

        // Get or create a send tracker for this run
        $totalQueued = EmailLog::where('status', 'queued')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $tracker = JobTracker::where('type', 'email_send')
            ->where('status', 'running')
            ->first();

        if (!$tracker) {
            $tracker = JobTracker::start('email_send', 'Sending emails', $totalQueued);
        }

        Log::channel('queue')->info("SendQueuedEmails: Processing {$emails->count()} queued emails");

        $totalSent = 0;
        $totalFailed = 0;

        // Group emails by resolved sender type
        $smtpEmails = collect();
        $mailgunBySender = collect(); // keyed by sender_name (or '' for global)

        foreach ($emails as $email) {
            $senderConfig = $email->sender_name ? SenderResolver::get($email->sender_name) : null;
            $senderType = $senderConfig['type'] ?? $defaultDriver;

            if ($senderType === 'pmta') {
                // PMTA emails should be 'spooled', not 'queued' — skip and log
                Log::channel('queue')->warning("SendQueuedEmails: PMTA email found with queued status, skipping", [
                    'email_log_id' => $email->id,
                    'sender_name' => $email->sender_name,
                ]);
                continue;
            } elseif ($senderType === 'mailgun') {
                $key = $email->sender_name ?? '';
                if (!$mailgunBySender->has($key)) {
                    $mailgunBySender->put($key, collect());
                }
                $mailgunBySender->get($key)->push($email);
            } else {
                $smtpEmails->push($email);
            }
        }

        // Send SMTP emails one by one
        foreach ($smtpEmails as $email) {
            try {
                $senderConfig = $email->sender_name ? SenderResolver::get($email->sender_name) : null;
                $this->sendSingleViaSmtp($email, $senderConfig);
                $totalSent++;
                $tracker->incrementProgress();
            } catch (Exception $e) {
                $totalFailed++;
                $tracker->incrementFailed();
                $tracker->incrementProgress();
                Log::channel('queue')->error("SMTP send failed for {$email->recipient}: " . $e->getMessage());
            }

            if ($delaySeconds > 0) {
                sleep($delaySeconds);
            }
        }

        // Send Mailgun emails in batches, grouped by sender
        foreach ($mailgunBySender as $senderKey => $senderEmails) {
            $senderConfig = $senderKey ? SenderResolver::get($senderKey) : null;
            $result = $this->sendViaMailgunBatch($senderEmails, $senderConfig);
            $totalSent += $result['sent'];
            $totalFailed += $result['failed'];
            $tracker->incrementProgress($result['sent'] + $result['failed']);
            if ($result['failed'] > 0) {
                $tracker->incrementFailed($result['failed']);
            }
        }

        // Mark old queued emails as skipped
        EmailLog::where('status', 'queued')
            ->where('created_at', '<', now()->subDay())
            ->update(['status' => 'skipped', 'error' => 'Email too old to process']);

        $tracker->flush();

        $duration = round(microtime(true) - $startTime, 2);
        Log::channel('queue')->info("SendQueuedEmails completed: {$totalSent} sent, {$totalFailed} failed in {$duration}s");
    }

    protected function sendSingleViaSmtp(EmailLog $emailLog, ?array $senderConfig = null): void
    {
        $unsubscribeUrl = $this->generateUnsubscribeUrl($emailLog);
        $mailer = $senderConfig['smtp_mailer'] ?? config('email-system.smtp.mailer', 'smtp');

        Mail::mailer($mailer)->send(new NewsletterMail($emailLog, $unsubscribeUrl, $senderConfig));

        $emailLog->update([
            'status' => 'sent',
            'error' => null,
        ]);

        AudienceUser::where('email', $emailLog->recipient)
            ->whereNull('sent_at')
            ->update(['sent_at' => now()]);

        Log::channel('queue')->info('Email sent via SMTP to: ' . $emailLog->recipient);
    }

    protected function sendViaMailgunBatch($emails, ?array $senderConfig = null): array
    {
        $batchSize = 500;
        $batchDelay = 2000;
        $totalSent = 0;
        $totalFailed = 0;

        $mgClient = Mailgun::create(
            $senderConfig['mailgun_secret'] ?? config('email-system.mailgun.secret'),
            $senderConfig['mailgun_endpoint'] ?? config('email-system.mailgun.endpoint', 'https://api.eu.mailgun.net')
        );
        $domain = $senderConfig['mailgun_domain'] ?? config('email-system.mailgun.domain');
        $fromAddress = $senderConfig['from_address'] ?? config('email-system.from.address');
        $fromName = $senderConfig['from_name'] ?? config('email-system.from.name');
        $replyToAddress = $senderConfig['reply_to'] ?? config('email-system.reply_to', $fromAddress);

        $byTemplate = $emails->groupBy('email_template_id');

        foreach ($byTemplate as $templateId => $templateEmails) {
            foreach ($templateEmails->chunk($batchSize) as $batch) {
                $result = $this->sendMailgunBatch($mgClient, $domain, $fromAddress, $fromName, $replyToAddress, $batch);
                $totalSent += $result['sent'];
                $totalFailed += $result['failed'];
                usleep($batchDelay * 1000);
            }
        }

        return ['sent' => $totalSent, 'failed' => $totalFailed];
    }

    protected function sendMailgunBatch($mgClient, $domain, $fromAddress, $fromName, $replyToAddress, $emails): array
    {
        $firstEmail = $emails->first();
        if (!$firstEmail) {
            return ['sent' => 0, 'failed' => 0];
        }

        $this->prepareUnsubscribeTokens($emails);

        $recipients = [];
        $recipientVariables = [];

        foreach ($emails as $email) {
            $recipients[] = $email->recipient;
            $recipientVariables[$email->recipient] = [
                'id' => $email->id,
                'unsubscribe_url' => $this->getUnsubscribeUrl($email),
            ];
        }

        $htmlContent = view('email-system::newsletter', [
            'emailLog' => $firstEmail,
            'subject' => $firstEmail->subject,
            'messageContent' => $firstEmail->message,
            'unsubscribeUrl' => '%recipient.unsubscribe_url%',
        ])->render();

        try {
            $response = $mgClient->messages()->send($domain, [
                'from' => "{$fromName} <{$fromAddress}>",
                'to' => $recipients,
                'subject' => $firstEmail->subject,
                'html' => $htmlContent,
                'h:Reply-To' => $replyToAddress,
                'recipient-variables' => json_encode($recipientVariables),
            ]);

            if ($response->getId()) {
                $messageId = trim($response->getId(), '<>');
                $emailIds = $emails->pluck('id')->toArray();

                EmailLog::whereIn('id', $emailIds)->update([
                    'status' => 'sent',
                    'error' => null,
                    'mailgun_message_id' => $messageId,
                ]);

                $recipientEmails = $emails->pluck('recipient')->toArray();
                AudienceUser::whereIn('email', $recipientEmails)
                    ->whereNull('sent_at')
                    ->update(['sent_at' => now()]);

                return ['sent' => count($emailIds), 'failed' => 0];
            }
        } catch (Exception $e) {
            Log::channel('queue')->error("Mailgun batch send failed: " . $e->getMessage());

            $emailIds = $emails->pluck('id')->toArray();
            EmailLog::whereIn('id', $emailIds)->update([
                'status' => 'failed',
                'error' => 'Batch failed: ' . substr($e->getMessage(), 0, 200),
            ]);

            return ['sent' => 0, 'failed' => count($emailIds)];
        }

        return ['sent' => 0, 'failed' => 0];
    }

    protected function generateUnsubscribeUrl(EmailLog $emailLog): ?string
    {
        $audienceUser = AudienceUser::where('email', $emailLog->recipient)
            ->where('is_active', true)
            ->first();

        if (!$audienceUser) {
            return null;
        }

        if (!$audienceUser->unsubscribe_token) {
            $token = bin2hex(random_bytes(16));
            AudienceUser::where('email', $emailLog->recipient)->update(['unsubscribe_token' => $token]);
        } else {
            $token = $audienceUser->unsubscribe_token;
        }

        return route('email-system.unsubscribe', [
            'email' => $emailLog->recipient,
            'token' => $token,
        ]);
    }

    protected function prepareUnsubscribeTokens($emails): void
    {
        $recipientEmails = $emails->pluck('recipient')->toArray();

        $usersWithoutToken = AudienceUser::whereIn('email', $recipientEmails)
            ->whereNull('unsubscribe_token')
            ->get();

        foreach ($usersWithoutToken as $user) {
            $token = bin2hex(random_bytes(16));
            AudienceUser::where('email', $user->email)
                ->whereNull('unsubscribe_token')
                ->update(['unsubscribe_token' => $token]);
        }
    }

    protected function getUnsubscribeUrl(EmailLog $emailLog): string
    {
        $audienceUser = AudienceUser::where('email', $emailLog->recipient)
            ->where('is_active', 1)
            ->first();

        if ($audienceUser && $audienceUser->unsubscribe_token) {
            return route('email-system.unsubscribe', [
                'email' => $emailLog->recipient,
                'token' => $audienceUser->unsubscribe_token,
            ]);
        }

        return config('app.url');
    }

    protected function sendCompletionNotification(): void
    {
        $callback = resolve_callback(config('email-system.send_completion_callback'));
        if (!$callback) {
            return;
        }

        $today = now()->startOfDay();

        $stats = [
            'sent' => EmailLog::where('status', 'sent')->where('created_at', '>=', $today)->count(),
            'failed' => EmailLog::where('status', 'failed')->where('created_at', '>=', $today)->count(),
            'opened' => EmailLog::where('status', 'sent')->where('created_at', '>=', $today)->where('opened', true)->count(),
        ];

        $callback($stats);
    }
}
