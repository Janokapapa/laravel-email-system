<?php

namespace JanDev\EmailSystem\Jobs;

use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\JobTracker;
use JanDev\EmailSystem\Services\ZeroBounce;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

use function JanDev\EmailSystem\resolve_callback;

class VerifyEmailsZeroBounceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries     = 1;
    public int $timeout   = 3600;
    public int $uniqueFor = 3600;

    /** @var callable|null Injectable batch validator for testing; null uses ZeroBounce::validateBatch() */
    private $validator = null;

    public function __construct(
        private readonly int $groupId,
        private readonly ?int $userId = null,
    ) {}

    /**
     * Unique job identifier — prevents duplicate dispatch for the same group.
     */
    public function uniqueId(): int
    {
        return $this->groupId;
    }

    /**
     * Inject a custom validator callable (for unit testing).
     * Signature: callable(string $email): ?array{'status': string, 'sub_status': string|null}
     * Note: internally each call result is collected into a batch result map.
     */
    public function setValidator(callable $fn): static
    {
        $this->validator = $fn;
        return $this;
    }

    public function handle(): void
    {
        $startTime         = microtime(true);
        $verified          = 0;
        $skipped           = 0;
        $errors            = 0;
        $consecutiveErrors = 0;
        $aborted           = false;

        // Normalize: bounced users should not be 'unverified' — fix before counting
        AudienceUser::where('email_audience_group_id', $this->groupId)
            ->where('bounced', true)
            ->where('zerobounce_status', '!=', 'bounced')
            ->update(['zerobounce_status' => 'bounced']);

        $totalUnverified = AudienceUser::where('email_audience_group_id', $this->groupId)
            ->where('zerobounce_status', 'unverified')
            ->where('bounced', false)
            ->count();

        $groupName = \JanDev\EmailSystem\Models\EmailAudienceGroup::find($this->groupId)?->name ?? "Group #{$this->groupId}";
        $tracker = JobTracker::start('zerobounce', "ZeroBounce — {$groupName}", $totalUnverified, [
            'group_id' => $this->groupId,
        ]);

        try {
            AudienceUser::where('email_audience_group_id', $this->groupId)
                ->where('zerobounce_status', 'unverified')
                ->where('bounced', false)
                ->chunkById(ZeroBounce::BATCH_SIZE, function ($users) use (
                    &$verified, &$skipped, &$errors, &$consecutiveErrors, &$aborted, $tracker
                ) {
                    if ($aborted) {
                        return false;
                    }

                    // Split users: those with existing cross-group results vs those needing API
                    $needsApi = [];
                    $crossGroupResults = [];

                    foreach ($users as $user) {
                        $existing = AudienceUser::where('email', $user->email)
                            ->where('id', '!=', $user->id)
                            ->whereNotNull('zerobounce_checked_at')
                            ->whereNotIn('zerobounce_status', ['unverified', 'bounced'])
                            ->first();

                        if ($existing) {
                            $crossGroupResults[$user->email] = [
                                'status'     => $existing->zerobounce_status,
                                'sub_status' => $existing->zerobounce_sub_status,
                            ];
                        } else {
                            $needsApi[] = $user->email;
                        }
                    }

                    // Batch validate emails that need API calls
                    $apiResults = [];
                    if (!empty($needsApi)) {
                        $apiResults = $this->validateBatch($needsApi);

                        if ($apiResults === null) {
                            $errors += count($needsApi);
                            $skipped += count($needsApi);
                            $consecutiveErrors++;
                            $tracker->incrementFailed(count($needsApi));
                            $tracker->incrementProgress(count($needsApi));

                            if ($consecutiveErrors >= 3) {
                                Log::channel('queue')->error(
                                    "VerifyEmailsZeroBounceJob: Aborting — 3 consecutive batch failures. " .
                                    "ZeroBounce API may be down. GroupId={$this->groupId}"
                                );
                                $aborted = true;
                                return false;
                            }

                            // Still apply cross-group results even if API failed
                            $apiResults = [];
                        } else {
                            $consecutiveErrors = 0;
                        }
                    }

                    // Merge results
                    $allResults = array_merge($crossGroupResults, $apiResults);
                    $now = Carbon::now();

                    foreach ($users as $user) {
                        $result = $allResults[$user->email] ?? null;

                        if ($result === null) {
                            continue;
                        }

                        // Update all lists where this email is unverified
                        AudienceUser::where('email', $user->email)
                            ->where('bounced', false)
                            ->where('zerobounce_status', 'unverified')
                            ->update([
                                'zerobounce_status'      => $result['status'],
                                'zerobounce_sub_status'  => $result['sub_status'],
                                'zerobounce_checked_at'  => $now,
                            ]);

                        $verified++;
                        $tracker->incrementProgress();
                    }

                    Log::channel('queue')->info(
                        "VerifyEmailsZeroBounceJob: Progress — {$verified} verified, {$skipped} skipped " .
                        "(GroupId={$this->groupId}, batch=" . count($needsApi) . " API + " . count($crossGroupResults) . " cached)"
                    );
                });

            $tracker->flush();
            $aborted ? $tracker->markFailed('Aborted — 3 consecutive batch failures') : $tracker->markCompleted();

            $duration = round(microtime(true) - $startTime, 2);

            Log::channel('queue')->info(
                "VerifyEmailsZeroBounceJob completed: {$verified} verified, {$skipped} skipped, " .
                "{$errors} errors in {$duration}s (GroupId={$this->groupId})"
            );

            $completionCallback = resolve_callback(config('email-system.zerobounce_completion_callback'));
            if ($completionCallback) {
                $completionCallback($this->userId, [
                    'verified'  => $verified,
                    'skipped'   => $skipped,
                    'errors'    => $errors,
                    'duration'  => $duration,
                    'group_id'  => $this->groupId,
                    'aborted'   => $aborted,
                ]);
            }
        } catch (\Exception $e) {
            $tracker->markFailed($e->getMessage());

            Log::channel('queue')->error(
                "VerifyEmailsZeroBounceJob failed: " . $e->getMessage() .
                " (GroupId={$this->groupId})"
            );

            $failureCallback = resolve_callback(config('email-system.zerobounce_failure_callback'));
            if ($failureCallback) {
                $failureCallback($this->userId, $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Validate a batch of emails. Uses the injectable validator in tests
     * (calling it per-email to maintain test compatibility), or falls back
     * to ZeroBounce::validateBatch() in production.
     *
     * @return array<string, array{status: string, sub_status: string|null}>|null
     */
    private function validateBatch(array $emails): ?array
    {
        if ($this->validator !== null) {
            // Test mode: call per-email validator and collect results
            $results = [];
            foreach ($emails as $email) {
                $result = ($this->validator)($email);
                if ($result === null) {
                    return null;
                }
                $results[$email] = $result;
            }
            return $results;
        }

        return ZeroBounce::validateBatch($emails);
    }
}
