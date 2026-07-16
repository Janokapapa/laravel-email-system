<?php

namespace JanDev\EmailSystem\Support;

use Illuminate\Support\Carbon;
use JanDev\EmailSystem\Models\EmailLog;

/**
 * Per-domain, per-provider RE-WARM limiter.
 *
 * When a sending domain's reputation with one inbox provider is damaged (e.g.
 * onlinecasinoevents.com hard spam-blocked by Gmail 5.7.1), a hard block stops
 * the bleed but never recovers it. This limiter enables a controlled re-warm:
 * hand at most `daily_cap` of that provider's recipients to PMTA per day, and
 * ONLY recipients who ENGAGED (clicked — reliable — or opened) within
 * `engaged_days`. Everyone else for that provider is deferred (left 'spooled').
 *
 * Engagement is USER-level (does this recipient click our mail at all), read
 * across every sender — not just this domain — because the damaged domain has,
 * by definition, almost no successful delivery history of its own.
 *
 * The pure decision method is DB-free and unit-tested; allow() holds per-run
 * counters (seeded from today's sent count) and a per-recipient engagement
 * cache. Mirrors {@see WarmupLimiter}.
 */
class ProviderRewarmLimiter
{
    /** provider => recipient LIKE patterns used to seed today's per-provider sent count. */
    private const PROVIDER_LIKE = [
        'gmail'     => ['%@gmail.%', '%@googlemail.%'],
        'yahoo'     => ['%@yahoo.%', '%@ymail.%', '%@rocketmail.%', '%@btinternet.%', '%@btopenworld.%', '%@sky.%', '%@talk21.%'],
        'icloud'    => ['%@icloud.%', '%@me.%', '%@mac.%'],
        'microsoft' => ['%@outlook.%', '%@hotmail.%', '%@live.%', '%@msn.%'],
    ];

    /** @var array<string,int> "domain|provider" => sent-today (running) */
    protected array $sentCount = [];
    /** @var array<string,bool> "recipient|days" => engaged */
    protected array $engagedCache = [];

    /**
     * Pure decision: would this send be deferred? Deferred when the recipient is
     * not engaged, the cap is disabled (<= 0), or the daily cap is already spent.
     */
    public static function wouldDefer(int $dailyCap, int $sentToday, bool $isEngaged): bool
    {
        if (!$isEngaged) {
            return true;
        }
        if ($dailyCap <= 0) {
            return true;
        }
        return $sentToday >= $dailyCap;
    }

    /**
     * May this (domain, provider, recipient) send be handed to PMTA now under the
     * given re-warm policy? Increments the running counter when allowed.
     *
     * @param array{daily_cap?:int|string, engaged_days?:int|string} $policy
     */
    public function allow(string $sendingDomain, string $recipient, string $provider, array $policy): bool
    {
        $domain = strtolower(trim($sendingDomain));
        $dailyCap = (int) ($policy['daily_cap'] ?? 0);
        $engagedDays = (int) ($policy['engaged_days'] ?? 180);

        $isEngaged = $this->isEngaged($recipient, $engagedDays);

        $key = $domain . '|' . $provider;
        if (!isset($this->sentCount[$key])) {
            $this->sentCount[$key] = $this->countSentToday($domain, $provider);
        }

        if (self::wouldDefer($dailyCap, $this->sentCount[$key], $isEngaged)) {
            return false;
        }

        $this->sentCount[$key]++;
        return true;
    }

    /**
     * Has this recipient clicked (reliable) or opened (best-effort) any of our
     * mail within the trailing window? Cached per (recipient, days) for the run.
     */
    public function isEngaged(string $recipient, int $engagedDays): bool
    {
        $key = strtolower($recipient) . '|' . $engagedDays;
        if (isset($this->engagedCache[$key])) {
            return $this->engagedCache[$key];
        }

        $cutoff = Carbon::now()->subDays(max(1, $engagedDays));
        $engaged = EmailLog::query()
            ->where('recipient', $recipient)
            ->where(function ($w) use ($cutoff) {
                $w->where('clicked_at', '>=', $cutoff)
                    ->orWhere('opened_at', '>=', $cutoff);
            })
            ->exists();

        return $this->engagedCache[$key] = $engaged;
    }

    /**
     * Count emails already handed to PMTA (status 'sent') today for this sending
     * domain and provider group.
     */
    protected function countSentToday(string $domain, string $provider): int
    {
        $likes = self::PROVIDER_LIKE[$provider] ?? [];
        if ($likes === []) {
            return 0;
        }

        return (int) EmailLog::query()
            ->where('status', 'sent')
            ->where('sent_at', '>=', Carbon::now()->startOfDay())
            ->where('sender', 'like', '%@' . $domain)
            ->where(function ($w) use ($likes) {
                foreach ($likes as $like) {
                    $w->orWhere('recipient', 'like', $like);
                }
            })
            ->count();
    }
}
