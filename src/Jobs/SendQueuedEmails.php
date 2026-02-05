<?php

namespace JanDev\EmailSystem\Jobs;

use Exception;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Mail\NewsletterMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mailgun\Mailgun;

class SendQueuedEmails implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $startTime = microtime(true);
        $driver = config('email-system.driver', 'smtp');

        $maxPerRun = config('email-system.send.max_per_run', 100);
        $delaySeconds = config('email-system.send.delay_seconds', 1);

        // Get queued emails from last 24 hours
        $emails = EmailLog::where('status', 'queued')
            ->where('created_at', '>=', now()->subDay())
            ->take($maxPerRun)
            ->get();

        if ($emails->isEmpty()) {
            Log::channel('queue')->info('SendQueuedEmails: No queued emails found');

            if (Cache::get('email_system_queue_active')) {
                $this->sendCompletionNotification();
                Cache::forget('email_system_queue_active');
            }
            return;
        }

        Cache::put('email_system_queue_active', true, now()->addHours(24));

        Log::channel('queue')->info("SendQueuedEmails: Processing {$emails->count()} emails via {$driver}");

        $totalSent = 0;
        $totalFailed = 0;

        if ($driver === 'mailgun') {
            $result = $this->sendViaMailgunBatch($emails);
            $totalSent = $result['sent'];
            $totalFailed = $result['failed'];
        } else {
            // SMTP - send one by one
            foreach ($emails as $email) {
                try {
                    $this->sendSingleViaSmtp($email);
                    $totalSent++;
                } catch (Exception $e) {
                    $totalFailed++;
                    Log::channel('queue')->error("SMTP send failed for {$email->recipient}: " . $e->getMessage());
                }

                // Delay between emails to avoid rate limiting
                if ($delaySeconds > 0) {
                    sleep($delaySeconds);
                }
            }
        }

        // Mark old queued emails as skipped
        EmailLog::where('status', 'queued')
            ->where('created_at', '<', now()->subDay())
            ->update(['status' => 'skipped', 'error' => 'Email too old to process']);

        $duration = round(microtime(true) - $startTime, 2);
        Log::channel('queue')->info("SendQueuedEmails completed: {$totalSent} sent, {$totalFailed} failed in {$duration}s");
    }

    protected function sendSingleViaSmtp(EmailLog $emailLog): void
    {
        $unsubscribeUrl = $this->generateUnsubscribeUrl($emailLog);
        $mailer = config('email-system.smtp.mailer', 'smtp');

        Mail::mailer($mailer)->send(new NewsletterMail($emailLog, $unsubscribeUrl));

        $emailLog->update([
            'status' => 'sent',
            'error' => null,
        ]);

        AudienceUser::where('email', $emailLog->recipient)
            ->whereNull('sent_at')
            ->update(['sent_at' => now()]);

        Log::channel('queue')->info('Email sent via SMTP to: ' . $emailLog->recipient);
    }

    protected function sendViaMailgunBatch($emails): array
    {
        $batchSize = 500;
        $batchDelay = 2000;
        $totalSent = 0;
        $totalFailed = 0;

        $mgClient = Mailgun::create(
            config('email-system.mailgun.secret'),
            config('email-system.mailgun.endpoint', 'https://api.eu.mailgun.net')
        );
        $domain = config('email-system.mailgun.domain');
        $fromAddress = config('email-system.from.address');
        $fromName = config('email-system.from.name');
        $replyToAddress = config('email-system.reply_to', $fromAddress);

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
        $callback = config('email-system.send_completion_callback');
        if (!is_callable($callback)) {
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
