<?php

namespace JanDev\EmailSystem\Jobs;

use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\EmailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

use function JanDev\EmailSystem\resolve_callback;

class QueueEmailsForAudience implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    protected int $templateId;
    protected int $audienceGroupId;
    protected bool $skipYahoo;
    protected ?int $userId;
    protected ?\Closure $onComplete = null;

    public function __construct(
        int $templateId,
        int $audienceGroupId,
        bool $skipYahoo = false,
        ?int $userId = null
    ) {
        $this->templateId = $templateId;
        $this->audienceGroupId = $audienceGroupId;
        $this->skipYahoo = $skipYahoo;
        $this->userId = $userId;
    }

    public function handle(): void
    {
        $startTime = microtime(true);

        $template = EmailTemplate::findOrFail($this->templateId);
        $audienceGroup = EmailAudienceGroup::findOrFail($this->audienceGroupId);

        Log::channel('queue')->info("QueueEmailsForAudience: Starting for template {$template->name}, audience {$audienceGroup->name}");

        // Get all bounced/inactive emails across ALL audience groups
        $blockedFromAudience = AudienceUser::where(function ($q) {
                $q->where('is_active', false)
                  ->orWhere('bounced', true);
            })
            ->pluck('email')
            ->toArray();

        // Get additional blocked emails from config callback
        $additionalBlocked = [];
        $blockedCallback = resolve_callback(config('email-system.blocked_emails_callback'));
        if ($blockedCallback) {
            $additionalBlocked = $blockedCallback();
        }

        // Merge both lists for O(1) lookup
        $blockedEmails = array_flip(array_unique(array_merge($blockedFromAudience, $additionalBlocked)));

        Log::channel('queue')->info("QueueEmailsForAudience: Blocked emails count: " . count($blockedEmails));

        // Get emails already sent/queued for this template (prevent duplicate sends)
        $alreadySentEmails = array_flip(
            EmailLog::where('email_template_id', $template->id)
                ->whereIn('status', ['sent', 'queued'])
                ->pluck('recipient')
                ->toArray()
        );

        Log::channel('queue')->info("QueueEmailsForAudience: Already sent/queued for this template: " . count($alreadySentEmails));

        // Get sender from config
        $sender = config('email-system.from.address');

        // Prepare batch insert data
        $batchData = [];
        $batchSize = 1000;
        $queuedCount = 0;
        $skippedCount = 0;
        $yahooSkippedCount = 0;
        $alreadySentSkippedCount = 0;

        // Process in chunks to avoid memory issues
        $audienceGroup->audienceUsers()
            ->where('is_active', true)
            ->where('bounced', false)
            ->chunkById(1000, function ($users) use (
                $template, $audienceGroup, $sender, $blockedEmails, $alreadySentEmails,
                &$batchData, &$queuedCount, &$skippedCount, &$yahooSkippedCount, &$alreadySentSkippedCount, $batchSize
            ) {
                foreach ($users as $user) {
                    // Skip if already sent/queued for this template
                    if (isset($alreadySentEmails[$user->email])) {
                        $alreadySentSkippedCount++;
                        continue;
                    }

                    // Skip Yahoo/Ymail if enabled
                    if ($this->skipYahoo && preg_match('/@(yahoo|ymail)\./i', $user->email)) {
                        $yahooSkippedCount++;
                        continue;
                    }

                    // Skip if blocked
                    if (isset($blockedEmails[$user->email])) {
                        $skippedCount++;
                        continue;
                    }

                    $batchData[] = [
                        'email_template_id' => $template->id,
                        'email_audience_group_id' => $audienceGroup->id,
                        'recipient' => $user->email,
                        'recipient_name' => $user->name,
                        'subject' => $template->subject,
                        'message' => $template->body,
                        'sender' => $sender,
                        'status' => 'queued',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $queuedCount++;

                    // Insert in batches
                    if (count($batchData) >= $batchSize) {
                        EmailLog::insert($batchData);
                        $batchData = [];
                    }
                }
            });

        // Insert remaining batch
        if (!empty($batchData)) {
            EmailLog::insert($batchData);
        }

        $duration = round(microtime(true) - $startTime, 2);

        Log::channel('queue')->info("QueueEmailsForAudience completed: {$queuedCount} queued, {$alreadySentSkippedCount} already sent, {$skippedCount} blocked, {$yahooSkippedCount} yahoo in {$duration}s");

        // Send notification callback if configured
        $notificationCallback = resolve_callback(config('email-system.queue_completion_callback'));
        if ($notificationCallback) {
            $notificationCallback($this->userId, [
                'queued' => $queuedCount,
                'skipped' => $skippedCount,
                'already_sent_skipped' => $alreadySentSkippedCount,
                'yahoo_skipped' => $yahooSkippedCount,
                'duration' => $duration,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('queue')->error("QueueEmailsForAudience failed: " . $exception->getMessage());

        $failureCallback = resolve_callback(config('email-system.queue_failure_callback'));
        if ($failureCallback) {
            $failureCallback($this->userId, $exception->getMessage());
        }
    }
}
