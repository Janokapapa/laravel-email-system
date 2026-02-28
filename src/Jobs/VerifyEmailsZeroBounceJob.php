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

    /** @var callable|null Injectable validator for testing; null uses ZeroBounce::validate() */
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

        $totalUnverified = AudienceUser::where('email_audience_group_id', $this->groupId)
            ->where('zerobounce_status', 'unverified')
            ->count();

        $groupName = \JanDev\EmailSystem\Models\EmailAudienceGroup::find($this->groupId)?->name ?? "Group #{$this->groupId}";
        $tracker = JobTracker::start('zerobounce', "ZeroBounce — {$groupName}", $totalUnverified, [
            'group_id' => $this->groupId,
        ]);

        try {
            AudienceUser::where('email_audience_group_id', $this->groupId)
                ->where('zerobounce_status', 'unverified')
                ->chunkById(100, function ($users) use (
                    &$verified, &$skipped, &$errors, &$consecutiveErrors, &$aborted, $tracker
                ) {
                    if ($aborted) {
                        return false; // Stop chunking
                    }

                    foreach ($users as $user) {
                        $result = $this->validateEmail($user->email);

                        if ($result === null) {
                            $errors++;
                            $consecutiveErrors++;
                            $skipped++;
                            $tracker->incrementFailed();
                            $tracker->incrementProgress();

                            if ($consecutiveErrors >= 10) {
                                Log::channel('queue')->error(
                                    "VerifyEmailsZeroBounceJob: Aborting — 10 consecutive API failures. " .
                                    "ZeroBounce API may be down. GroupId={$this->groupId}"
                                );
                                $aborted = true;
                                return false; // Stop chunk
                            }

                            continue;
                        }

                        // Successful API call — reset consecutive error counter
                        $consecutiveErrors = 0;

                        $user->update([
                            'zerobounce_status'      => $result['status'],
                            'zerobounce_sub_status'  => $result['sub_status'],
                            'zerobounce_checked_at'  => Carbon::now(),
                        ]);

                        $verified++;
                        $tracker->incrementProgress();

                        $delayMs = config('email-system.zerobounce.delay_ms', 200);
                        if ($delayMs > 0) {
                            usleep($delayMs * 1000);
                        }
                    }

                    Log::channel('queue')->info(
                        "VerifyEmailsZeroBounceJob: Progress — {$verified} verified, {$skipped} skipped " .
                        "(GroupId={$this->groupId})"
                    );
                });

            $tracker->flush();
            $aborted ? $tracker->markFailed('Aborted — 10 consecutive API failures') : $tracker->markCompleted();

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
     * Validate an email address. Uses the injectable validator in tests,
     * or falls back to the ZeroBounce service in production.
     */
    private function validateEmail(string $email): ?array
    {
        if ($this->validator !== null) {
            return ($this->validator)($email);
        }

        return ZeroBounce::validate($email);
    }
}
