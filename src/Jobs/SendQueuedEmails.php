<?php

namespace JanDev\EmailSystem\Jobs;

use Exception;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\AudienceUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mailgun\Mailgun;

class SendQueuedEmails implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected int $batchSize = 500;
    protected int $maxPerRun = 1000;
    protected int $batchDelay = 2000;

    public function handle()
    {
        $startTime = microtime(true);

        // Get queued emails from last 24 hours
        $emails = EmailLog::where('status', 'queued')
            ->where('created_at', '>=', now()->subDay())
            ->take($this->maxPerRun)
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

        Log::channel('queue')->info("SendQueuedEmails: Processing {$emails->count()} emails in batch mode");

        $mgClient = Mailgun::create(
            config('email-system.mailgun.secret'),
            config('email-system.mailgun.endpoint', 'https://api.eu.mailgun.net')
        );
        $domain = config('email-system.mailgun.domain');
        $fromAddress = config('email-system.from.address');
        $fromName = config('email-system.from.name');
        $replyToAddress = config('email-system.reply_to', $fromAddress);

        $totalSent = 0;
        $totalFailed = 0;

        // Group by template
        $byTemplate = $emails->groupBy('email_template_id');

        foreach ($byTemplate as $templateId => $templateEmails) {
            foreach ($templateEmails->chunk($this->batchSize) as $batch) {
                $result = $this->sendBatch(
                    $mgClient,
                    $domain,
                    $fromAddress,
                    $fromName,
                    $replyToAddress,
                    $batch
                );

                $totalSent += $result['sent'];
                $totalFailed += $result['failed'];

                usleep($this->batchDelay * 1000);
            }
        }

        // Mark old queued emails as skipped
        EmailLog::where('status', 'queued')
            ->where('created_at', '<', now()->subDay())
            ->update(['status' => 'skipped', 'error' => 'Email too old to process']);

        $duration = round(microtime(true) - $startTime, 2);
        Log::channel('queue')->info("SendQueuedEmails completed: {$totalSent} sent, {$totalFailed} failed in {$duration}s");
    }

    protected function sendBatch(
        Mailgun $mgClient,
        string $domain,
        string $fromAddress,
        string $fromName,
        string $replyToAddress,
        $emails
    ): array {
        $sent = 0;
        $failed = 0;

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

                $sent = count($emailIds);
                Log::channel('queue')->info("Batch sent: {$sent} emails, Message ID: {$messageId}");
            }
        } catch (Exception $e) {
            Log::channel('queue')->error("Batch send failed: " . $e->getMessage());

            $emailIds = $emails->pluck('id')->toArray();
            EmailLog::whereIn('id', $emailIds)->update([
                'status' => 'failed',
                'error' => 'Batch failed: ' . substr($e->getMessage(), 0, 200),
            ]);

            $failed = count($emailIds);
        }

        return ['sent' => $sent, 'failed' => $failed];
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
        // Check for custom unsubscribe URL generator
        $customGenerator = config('email-system.unsubscribe_url_generator');
        if (is_callable($customGenerator)) {
            $url = $customGenerator($emailLog);
            if ($url) {
                return $url;
            }
        }

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
