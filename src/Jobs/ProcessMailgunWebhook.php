<?php

namespace JanDev\EmailSystem\Jobs;

use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\BouncedEmail;
use JanDev\EmailSystem\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use function JanDev\EmailSystem\resolve_callback;

class ProcessMailgunWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    protected array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function handle(): void
    {
        $event = $this->data['event'] ?? null;

        switch ($event) {
            case 'failed':
            case 'bounced':
                $this->handleBounce();
                break;

            case 'complained':
                $this->handleComplaint();
                break;

            case 'unsubscribed':
                $this->handleUnsubscribe();
                break;

            case 'delivered':
                $this->handleDelivered();
                break;

            case 'opened':
                $this->handleOpened();
                break;

            case 'clicked':
                $this->handleClicked();
                break;
        }
    }

    private function handleBounce(): void
    {
        $recipient = $this->data['recipient'] ?? null;
        $recipient = $recipient ? strtolower(trim($recipient)) : null;
        $messageId = $this->data['message_id'] ?? null;
        $severity = $this->data['severity'] ?? null;

        // Only process hard (permanent) bounces
        if ($severity !== 'permanent') {
            return;
        }

        $deliveryStatus = $this->data['delivery_status'] ?? [];
        $errorCode = $deliveryStatus['code'] ?? null;
        $errorMessage = $deliveryStatus['message'] ?? $deliveryStatus['description'] ?? 'Unknown error';
        $bounceReason = $errorCode ? "[$errorCode] $errorMessage" : $errorMessage;

        if ($messageId && $recipient) {
            EmailLog::where('mailgun_message_id', $messageId)
                ->where('recipient', $recipient)
                ->update([
                    'status' => 'failed',
                    'bounce_type' => 'hard',
                    'bounce_reason' => $bounceReason,
                    'bounced_at' => now(),
                ]);
        }

        if ($recipient) {
            // Save to global bounce registry
            BouncedEmail::updateOrCreate(
                ['email' => $recipient],
                [
                    'bounce_type' => 'hard',
                    'bounce_reason' => $bounceReason,
                    'source' => 'mailgun',
                    'bounced_at' => now(),
                ]
            );

            AudienceUser::where('email', $recipient)->update([
                'bounced' => true,
                'bounce_type' => 'hard',
                'bounce_reason' => $bounceReason,
                'bounced_at' => now(),
                'is_active' => false,
                'zerobounce_status' => 'bounced',
            ]);

            // Call custom bounce handler if configured
            try {
                $bounceHandler = resolve_callback(config('email-system.bounce_handler'));
                if ($bounceHandler) {
                    $bounceHandler($recipient, $bounceReason);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function handleComplaint(): void
    {
        $recipient = $this->data['recipient'] ?? null;
        $recipient = $recipient ? strtolower(trim($recipient)) : null;
        $messageId = $this->data['message_id'] ?? null;

        if ($recipient && $messageId) {
            EmailLog::where('mailgun_message_id', $messageId)
                ->where('recipient', $recipient)
                ->update([
                    'complained' => true,
                    'complained_at' => now(),
                ]);
        }

        if ($recipient) {
            AudienceUser::where('email', $recipient)->update(['is_active' => false]);

            // Save to global bounce registry so re-imports are blocked by AudienceUserObserver
            BouncedEmail::updateOrCreate(
                ['email' => $recipient],
                [
                    'bounce_type' => 'complaint',
                    'bounce_reason' => 'Mailgun spam complaint',
                    'source' => 'mailgun',
                    'bounced_at' => now(),
                ]
            );

            $complaintHandler = resolve_callback(config('email-system.complaint_handler'));
            if ($complaintHandler) {
                $complaintHandler($recipient);
            }
        }
    }

    private function handleUnsubscribe(): void
    {
        $recipient = $this->data['recipient'] ?? null;
        $recipient = $recipient ? strtolower(trim($recipient)) : null;

        if ($recipient) {
            AudienceUser::where('email', $recipient)->update(['is_active' => false]);

            $unsubscribeHandler = resolve_callback(config('email-system.unsubscribe_handler'));
            if ($unsubscribeHandler) {
                $unsubscribeHandler($recipient);
            }
        }
    }

    private function handleDelivered(): void
    {
        $messageId = $this->data['message_id'] ?? null;
        $recipient = $this->data['recipient'] ?? null;
        $timestamp = $this->data['timestamp'] ?? null;

        if ($messageId && $recipient) {
            $deliveredAt = $timestamp ? \Carbon\Carbon::createFromTimestamp($timestamp) : now();

            EmailLog::where('mailgun_message_id', $messageId)
                ->where('recipient', $recipient)
                ->whereNotIn('status', ['queued', 'cancelled', 'delivered'])
                ->update([
                    'status' => 'delivered',
                    'delivered_at' => $deliveredAt,
                    'bounce_type' => null,
                    'bounce_reason' => null,
                    'bounced_at' => null,
                ]);
        }
    }

    private function handleOpened(): void
    {
        $messageId = $this->data['message_id'] ?? null;

        if ($messageId) {
            $emailLog = EmailLog::where('mailgun_message_id', $messageId)
                ->where('opened', false)
                ->first();

            if ($emailLog) {
                $emailLog->markAsOpened();
            }
        }
    }

    private function handleClicked(): void
    {
        $messageId = $this->data['message_id'] ?? null;

        if ($messageId) {
            $emailLog = EmailLog::where('mailgun_message_id', $messageId)
                ->where('clicked', false)
                ->first();

            if ($emailLog) {
                $emailLog->markAsClicked();
            }
        }
    }
}
