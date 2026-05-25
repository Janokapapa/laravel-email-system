<?php

namespace JanDev\EmailSystem\Filament\Concerns;

use Illuminate\Support\Facades\Cache;
use JanDev\EmailSystem\Models\PmtaStatsBucket;

/**
 * Unified period-data loader for PMTA stats pages.
 *
 * Strategy:
 *   - period 1d / 7d  → Cache (push-stats.py sliding-window output)
 *   - period 14d / 30d → DB SUM(pmta_stats_buckets where granularity=day, last N days)
 *
 * The DB fallback is necessary because PMTA `acct-*.csv` logs are retained
 * for ~8 days on the servers, so push-stats.py cannot compute the 14/30-day
 * sliding window from local files. The Laravel side instead aggregates the
 * persisted daily buckets that accumulate over time via `--backfill` cron.
 */
trait PmtaPeriodDataLoader
{
    private const CACHE_PERIODS = [1, 7];
    private const DOMAIN_GROUPS = ['Gmail', 'Microsoft', 'Yahoo', 'iCloud', 'Other'];

    protected function loadPeriodPayload(string $server, int $period): ?array
    {
        if (in_array($period, self::CACHE_PERIODS, true)) {
            $cached = Cache::get("pmta_stats:{$server}:{$period}");
            if ($cached !== null) {
                $cached['totals'] = array_merge(
                    ['bounced_stop_hard' => 0, 'bounced_stop_queue' => 0],
                    $cached['totals'] ?? []
                );
            }
            return $cached;
        }

        return $this->aggregateDailyBuckets($server, $period);
    }

    private function aggregateDailyBuckets(string $server, int $period): ?array
    {
        $cutoff = now()->subDays($period - 1)->startOfDay();

        $buckets = PmtaStatsBucket::where('server', $server)
            ->where('granularity', 'day')
            ->where('bucket_start', '>=', $cutoff)
            ->get();

        if ($buckets->isEmpty()) {
            return null;
        }

        $totals = [
            'delivered' => 0,
            'bounced_stop' => 0,
            'bounced_stop_hard' => 0,
            'bounced_stop_queue' => 0,
            'bounced_go' => 0,
        ];

        $domains = array_fill_keys(self::DOMAIN_GROUPS, ['delivered' => 0, 'bounced' => 0]);
        $ips = [];
        $latest = null;

        foreach ($buckets as $b) {
            $totals['delivered'] += $b->delivered;
            $totals['bounced_stop'] += $b->bounced_stop;
            $totals['bounced_stop_hard'] += $b->bounced_stop_hard;
            $totals['bounced_stop_queue'] += $b->bounced_stop_queue;
            $totals['bounced_go'] += $b->bounced_go;

            foreach (($b->domains ?? []) as $group => $row) {
                if (!isset($domains[$group])) {
                    continue;
                }
                $domains[$group]['delivered'] += (int) ($row['delivered'] ?? 0);
                $domains[$group]['bounced'] += (int) ($row['bounced'] ?? 0);
            }

            foreach (($b->ips ?? []) as $ip => $row) {
                $ips[$ip] = $ips[$ip] ?? ['delivered' => 0, 'bounced' => 0];
                $ips[$ip]['delivered'] += (int) ($row['delivered'] ?? 0);
                $ips[$ip]['bounced'] += (int) ($row['bounced'] ?? 0);
            }

            if ($latest === null || $b->bucket_start > $latest) {
                $latest = $b->bucket_start;
            }
        }

        return [
            'totals' => $totals,
            'domains' => $domains,
            'ips' => $ips,
            'generated_at' => $latest?->toIso8601String(),
        ];
    }
}
