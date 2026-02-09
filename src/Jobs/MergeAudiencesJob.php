<?php

namespace JanDev\EmailSystem\Jobs;

use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

use function JanDev\EmailSystem\resolve_callback;

class MergeAudiencesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    protected array $sourceIds;
    protected int $targetId;
    protected bool $deleteSources;
    protected ?int $userId;

    public function __construct(array $sourceIds, int $targetId, bool $deleteSources, ?int $userId = null)
    {
        $this->sourceIds = $sourceIds;
        $this->targetId = $targetId;
        $this->deleteSources = $deleteSources;
        $this->userId = $userId;
    }

    public function handle(): void
    {
        $startTime = microtime(true);

        try {
            $movedCount = 0;
            $skippedCount = 0;

            // Get existing emails in target (just emails, memory efficient)
            $existingEmails = AudienceUser::where('email_audience_group_id', $this->targetId)
                ->pluck('email')
                ->flip()
                ->toArray();

            foreach ($this->sourceIds as $sourceId) {
                // Process in chunks to avoid memory issues
                AudienceUser::where('email_audience_group_id', $sourceId)
                    ->chunkById(1000, function ($users) use (&$existingEmails, &$movedCount, &$skippedCount) {
                        $toMove = [];
                        $toDelete = [];

                        foreach ($users as $user) {
                            if (isset($existingEmails[$user->email])) {
                                $toDelete[] = $user->id;
                                $skippedCount++;
                            } else {
                                $toMove[] = $user->id;
                                $existingEmails[$user->email] = true;
                                $movedCount++;
                            }
                        }

                        if (!empty($toMove)) {
                            AudienceUser::whereIn('id', $toMove)
                                ->update(['email_audience_group_id' => $this->targetId]);
                        }

                        if (!empty($toDelete)) {
                            AudienceUser::whereIn('id', $toDelete)->delete();
                        }
                    });

                if ($this->deleteSources) {
                    $remaining = AudienceUser::where('email_audience_group_id', $sourceId)->count();
                    if ($remaining === 0) {
                        EmailAudienceGroup::find($sourceId)?->delete();
                    }
                }
            }

            $duration = round(microtime(true) - $startTime, 2);

            Log::channel('queue')->info("MergeAudiencesJob completed: {$movedCount} moved, {$skippedCount} skipped in {$duration}s");

            $completionCallback = resolve_callback(config('email-system.merge_completion_callback'));
            if ($completionCallback) {
                $completionCallback($this->userId, [
                    'moved' => $movedCount,
                    'skipped' => $skippedCount,
                    'duration' => $duration,
                    'target_id' => $this->targetId,
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('queue')->error("MergeAudiencesJob failed: " . $e->getMessage());

            $failureCallback = resolve_callback(config('email-system.merge_failure_callback'));
            if ($failureCallback) {
                $failureCallback($this->userId, $e->getMessage());
            }

            throw $e;
        }
    }
}
