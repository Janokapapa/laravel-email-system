<?php

namespace JanDev\EmailSystem\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\EmailSendingDomain;

/**
 * Per-sending-domain warmup daily-cap limiter.
 *
 * A fresh sending (From/DKIM) domain must not be over-sent on day one — that
 * burns its reputation. This enforces an automatic per-domain daily volume cap
 * that ramps up (doubles) each calendar day since the domain's first send:
 *
 *   daily_cap = base * (factor ** warmup_day_index)
 *
 * where warmup_day_index = whole calendar days elapsed since first_sent_at
 * (day 0 = first day). Once the curve reaches `max_daily` the domain is
 * considered warmed up and the overall cap is lifted.
 *
 * A SEPARATE, always-on iCloud sub-cap (iCloud is volume-sensitive) limits
 * iCloud recipients to min(icloud_daily_cap, daily_cap) per domain per day.
 *
 * The pure curve/decision methods are DB-free and unit-tested. The stateful
 * allow() holds per-run running counters (seeded from today's sent count) so
 * that emails handed to PMTA within the same run are counted too.
 */
class WarmupLimiter
{
    protected bool $enabled;
    protected int $base;
    protected int $factor;
    protected int $maxDaily;
    protected int $icloudDailyCap;

    /** @var array<string,int> domain => overall sent-today (running) */
    protected array $overallCount = [];
    /** @var array<string,int> domain => iCloud sent-today (running) */
    protected array $icloudCount = [];
    /** @var array<string,int> domain => warmup day index */
    protected array $dayIndex = [];
    /** @var array<string,bool> domain => per-domain warmup enabled */
    protected array $warmupEnabledFor = [];
    /** @var array<string,int|null> domain => per-domain max_daily override */
    protected array $maxDailyFor = [];

    public function __construct(?array $config = null)
    {
        $config = $config ?? (array) config('email-system.warmup', []);
        $this->enabled = (bool) ($config['enabled'] ?? true);
        $this->base = (int) ($config['base'] ?? 50);
        $this->factor = (int) ($config['factor'] ?? 2);
        $this->maxDaily = (int) ($config['max_daily'] ?? 1_000_000);
        $this->icloudDailyCap = (int) ($config['icloud_daily_cap'] ?? 60);
    }

    // ---------------------------------------------------------------------
    // Pure curve / decision logic (DB-free, unit-tested)
    // ---------------------------------------------------------------------

    /**
     * Overall daily cap for a given warmup day index, or null when the domain
     * is warmed up (cap has reached/passed max_daily => no overall cap).
     * Computed iteratively to avoid integer overflow at large day indexes.
     */
    public function capForDayIndex(int $dayIndex, ?int $maxDailyOverride = null): ?int
    {
        $max = $maxDailyOverride ?? $this->maxDaily;
        $cap = $this->base;
        for ($i = 0, $n = max(0, $dayIndex); $i < $n; $i++) {
            $cap *= $this->factor;
            if ($cap >= $max) {
                return null; // warmed up
            }
        }
        return $cap >= $max ? null : $cap;
    }

    public function isComplete(int $dayIndex, ?int $maxDailyOverride = null): bool
    {
        return $this->capForDayIndex($dayIndex, $maxDailyOverride) === null;
    }

    /**
     * Effective iCloud sub-cap = min(icloud_daily_cap, overall daily cap).
     * When warmed up (overall unlimited) the iCloud cap still applies on its own.
     */
    public function icloudCapForDayIndex(int $dayIndex, ?int $maxDailyOverride = null): int
    {
        $overall = $this->capForDayIndex($dayIndex, $maxDailyOverride);
        return $overall === null ? $this->icloudDailyCap : min($this->icloudDailyCap, $overall);
    }

    /**
     * Whole calendar days elapsed since first_sent_at. First send day = 0.
     * Null first_sent_at (brand-new domain) => 0.
     */
    public static function dayIndexFor(?CarbonInterface $firstSentAt, ?CarbonInterface $now = null): int
    {
        if ($firstSentAt === null) {
            return 0;
        }
        $now = $now ?? Carbon::now();
        return (int) $firstSentAt->copy()->startOfDay()->diffInDays($now->copy()->startOfDay());
    }

    /**
     * Pure decision: would this send be deferred given the current counts?
     * (No side effects, no DB — the unit-testable heart of the limiter.)
     */
    public function wouldDefer(
        int $dayIndex,
        int $overallSentToday,
        int $icloudSentToday,
        bool $isIcloud,
        bool $domainWarmupEnabled = true,
        ?int $maxDailyOverride = null
    ): bool {
        if (!$this->enabled || !$domainWarmupEnabled) {
            return false;
        }

        if ($isIcloud && $icloudSentToday >= $this->icloudCapForDayIndex($dayIndex, $maxDailyOverride)) {
            return true;
        }

        $overallCap = $this->capForDayIndex($dayIndex, $maxDailyOverride);
        if ($overallCap !== null && $overallSentToday >= $overallCap) {
            return true;
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // Stateful, DB-backed enforcement (used by the send path)
    // ---------------------------------------------------------------------

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Decide whether an email for the given sending domain + recipient may be
     * handed to PMTA now. Returns true (allowed) and increments the running
     * counters, or false (defer — leave it queued for a later/higher-cap day).
     *
     * Records first_sent_at on the domain's first ever allowed send.
     */
    public function allow(string $sendingDomain, string $recipient): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $domain = strtolower(trim($sendingDomain));
        if ($domain === '') {
            return true; // no resolvable sending domain — do not block
        }

        $this->ensureLoaded($domain);

        if (!$this->warmupEnabledFor[$domain]) {
            return true;
        }

        $isIcloud = ProviderResolver::resolve($recipient) === 'icloud';

        if ($this->wouldDefer(
            $this->dayIndex[$domain],
            $this->overallCount[$domain],
            $this->icloudCount[$domain],
            $isIcloud,
            true,
            $this->maxDailyFor[$domain]
        )) {
            return false;
        }

        $this->overallCount[$domain]++;
        if ($isIcloud) {
            $this->icloudCount[$domain]++;
        }

        return true;
    }

    /**
     * Lazily load per-domain state (record + today's sent counts) on first use.
     */
    protected function ensureLoaded(string $domain): void
    {
        if (isset($this->dayIndex[$domain])) {
            return;
        }

        $record = EmailSendingDomain::firstOrCreate(
            ['domain' => $domain],
            ['first_sent_at' => Carbon::now()]
        );

        if ($record->first_sent_at === null) {
            $record->forceFill(['first_sent_at' => Carbon::now()])->save();
        }

        $this->warmupEnabledFor[$domain] = (bool) ($record->warmup_enabled ?? true);
        $this->maxDailyFor[$domain] = $record->max_daily !== null ? (int) $record->max_daily : null;
        $this->dayIndex[$domain] = self::dayIndexFor($record->first_sent_at);
        $this->overallCount[$domain] = $this->countSentToday($domain, false);
        $this->icloudCount[$domain] = $this->countSentToday($domain, true);
    }

    /**
     * Count emails already marked sent today for this sending domain.
     * Uses email_logs.sent_at (set at send time) + a from-address suffix match.
     * When $icloudOnly, narrows to iCloud-provider recipients.
     */
    protected function countSentToday(string $domain, bool $icloudOnly): int
    {
        $query = EmailLog::query()
            ->where('status', 'sent')
            ->where('sent_at', '>=', Carbon::now()->startOfDay())
            ->where('sender', 'like', '%@' . $domain);

        if ($icloudOnly) {
            $query->where(function ($w) {
                $w->where('recipient', 'like', '%@icloud.%')
                    ->orWhere('recipient', 'like', '%@me.%')
                    ->orWhere('recipient', 'like', '%@mac.%');
            });
        }

        return (int) $query->count();
    }
}
