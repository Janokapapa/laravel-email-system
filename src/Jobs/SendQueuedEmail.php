<?php

namespace JanDev\EmailSystem\Jobs;

use Exception;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\AudienceUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            ]);
            return;
        }

        try {
            DB::transaction(function () {
                $unsubscribeUrl = $this->generateUnsubscribeUrl();

                $mgClient = Mailgun::create(
                    config('email-system.mailgun.secret'),
                    config('email-system.mailgun.endpoint', 'https://api.eu.mailgun.net')
                );

                $domain = config('email-system.mailgun.domain');

                $htmlContent = view('email-system::newsletter', [
                    'emailLog' => $this->emailLog,
                    'subject' => $this->emailLog->subject,
                    'messageContent' => $this->emailLog->message,
                    'unsubscribeUrl' => $unsubscribeUrl,
                ])->render();

                $fromAddress = config('email-system.from.address');
                $fromName = config('email-system.from.name');
                $replyTo = config('email-system.reply_to', $fromAddress);

                $response = $mgClient->messages()->send($domain, [
                    'from' => "{$fromName} <{$fromAddress}>",
                    'to' => $this->emailLog->recipient,
                    'subject' => $this->emailLog->subject,
                    'html' => $htmlContent,
                    'h:Reply-To' => $replyTo,
                ]);

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

                    Log::channel('queue')->info('Email sent. Message ID: ' . $messageId);
                } else {
                    $this->emailLog->update([
                        'status' => 'failed',
                        'error' => json_encode($response),
                    ]);
                }
            });
        } catch (Exception $e) {
            $this->emailLog->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            Log::channel('queue')->error('SendQueuedEmail error: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function generateUnsubscribeUrl(): ?string
    {
        $audienceUsers = AudienceUser::where('email', $this->emailLog->recipient)
            ->where('is_active', true)
            ->lockForUpdate()
            ->get();

        if ($audienceUsers->isEmpty()) {
            return null;
        }

        $token = bin2hex(random_bytes(16));

        foreach ($audienceUsers as $audienceUser) {
            $audienceUser->update(['unsubscribe_token' => $token]);
        }

        return route('email-system.unsubscribe', [
            'email' => $this->emailLog->recipient,
            'token' => $token,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->emailLog->update([
            'status' => 'failed',
            'error' => 'Final failure: ' . $exception->getMessage(),
        ]);
    }
}
