<?php

namespace JanDev\EmailSystem\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JanDev\EmailSystem\Models\PmtaStatsSnapshot;

class PmtaStatsController extends Controller
{
    private const EXPECTED_DOMAIN_KEYS = ['Gmail', 'Microsoft', 'Yahoo', 'iCloud', 'Other'];

    private const ALLOWED_PERIODS = [1, 7, 14, 30];

    private const ALLOWED_GRANULARITIES = ['hour', 'day'];

    private const ISO8601_UTC_RE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    /**
     * Handle PMTA statistics push from each PMTA server.
     *
     * Called by /var/www/pmta/scripts/push-stats.py (hourly cron on each server).
     * Persists:
     *   - sliding window snapshot per period to `pmta_stats_snapshots` (one row per push)
     *   - discrete delta buckets to `pmta_stats_buckets` (upsert with MAX-guard)
     *   - latest snapshot in cache for 2h (UI fast path)
     */
    public function handle(Request $request): \Illuminate\Http\JsonResponse
    {
        $expectedKey = config('email-system.pmta.bounce_api_key');
        $receivedKey = $request->header('X-API-Key');

        if (empty($expectedKey) || !is_string($receivedKey) || !hash_equals($expectedKey, $receivedKey)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'server' => 'required|string|max:50',
            'generated_at' => 'sometimes|date',
            'periods' => 'required|array',
            'granularity' => 'sometimes|string|in:hour,day',
            'buckets' => 'sometimes|array',
        ]);

        $allowedServers = config('email-system.pmta.servers', []);
        $serverName = $validated['server'];

        if (!in_array($serverName, $allowedServers, true)) {
            return response()->json(['error' => 'Unknown server'], 422);
        }

        $periods = $validated['periods'];
        $periodKeys = array_map('intval', array_keys($periods));

        foreach ($periodKeys as $days) {
            if (!in_array($days, self::ALLOWED_PERIODS, true)) {
                return response()->json(['error' => "Invalid period: {$days}"], 422);
            }
        }

        foreach ($periods as $days => $periodData) {
            if (!is_array($periodData)) {
                return response()->json(['error' => "Period {$days} must be an array"], 422);
            }

            if (!isset($periodData['totals']) || !is_array($periodData['totals'])) {
                return response()->json(['error' => "Period {$days}: totals required"], 422);
            }

            $totals = $periodData['totals'];
            foreach (['delivered', 'bounced_stop', 'bounced_go'] as $key) {
                if (!isset($totals[$key]) || !is_int($totals[$key]) || $totals[$key] < 0) {
                    return response()->json(['error' => "Period {$days}: totals.{$key} must be a non-negative integer"], 422);
                }
            }

            if (!isset($periodData['domains']) || !is_array($periodData['domains'])) {
                return response()->json(['error' => "Period {$days}: domains required"], 422);
            }

            $domainKeys = array_keys($periodData['domains']);
            sort($domainKeys);
            $expectedKeys = self::EXPECTED_DOMAIN_KEYS;
            sort($expectedKeys);

            if ($domainKeys !== $expectedKeys) {
                return response()->json(['error' => "Period {$days}: invalid domain keys"], 422);
            }

            foreach ($periodData['domains'] as $group => $domainData) {
                if (!is_array($domainData) || !isset($domainData['delivered'], $domainData['bounced'])) {
                    return response()->json(['error' => "Period {$days}: domain {$group} must have delivered and bounced"], 422);
                }
            }

            if (isset($periodData['ips']) && is_array($periodData['ips'])) {
                foreach ($periodData['ips'] as $ip => $ipData) {
                    if (!is_array($ipData) || !isset($ipData['delivered'], $ipData['bounced'])) {
                        return response()->json(['error' => "Period {$days}: IP {$ip} must have delivered and bounced"], 422);
                    }
                }
            }
        }

        $granularity = $validated['granularity'] ?? 'hour';
        $buckets = $validated['buckets'] ?? [];

        if (!empty($buckets)) {
            foreach ($buckets as $bucketIso => $bucketData) {
                if (!is_string($bucketIso) || !preg_match(self::ISO8601_UTC_RE, $bucketIso)) {
                    return response()->json(['error' => "Invalid bucket timestamp: {$bucketIso}"], 422);
                }

                if ($granularity === 'day' && !str_ends_with($bucketIso, 'T00:00:00Z')) {
                    return response()->json(['error' => "Day-granularity buckets must use midnight UTC: {$bucketIso}"], 422);
                }

                if (!is_array($bucketData) || !isset($bucketData['totals']) || !is_array($bucketData['totals'])) {
                    return response()->json(['error' => "Bucket {$bucketIso}: totals required"], 422);
                }

                foreach (['delivered', 'bounced_stop', 'bounced_go'] as $key) {
                    if (!isset($bucketData['totals'][$key]) || !is_int($bucketData['totals'][$key]) || $bucketData['totals'][$key] < 0) {
                        return response()->json(['error' => "Bucket {$bucketIso}: totals.{$key} must be a non-negative integer"], 422);
                    }
                }

                if (!isset($bucketData['domains']) || !is_array($bucketData['domains'])) {
                    return response()->json(['error' => "Bucket {$bucketIso}: domains required"], 422);
                }

                $bucketDomainKeys = array_keys($bucketData['domains']);
                sort($bucketDomainKeys);
                $expectedKeys = self::EXPECTED_DOMAIN_KEYS;
                sort($expectedKeys);

                if ($bucketDomainKeys !== $expectedKeys) {
                    return response()->json(['error' => "Bucket {$bucketIso}: invalid domain keys"], 422);
                }
            }
        }

        // 1) Cache: keep the existing fast-path. Independent of DB.
        try {
            foreach ($periods as $days => $periodData) {
                $cacheKey = "pmta_stats:{$serverName}:{$days}";
                Cache::put($cacheKey, [
                    'server' => $serverName,
                    'period_days' => (int) $days,
                    'generated_at' => $validated['generated_at'] ?? null,
                    'totals' => $periodData['totals'],
                    'domains' => $periodData['domains'],
                    'ips' => $periodData['ips'] ?? [],
                ], now()->addHours(2));
            }
        } catch (\Throwable $e) {
            Log::error('[pmta-stats] Cache write failed', ['err' => $e->getMessage()]);
        }

        // 2) DB persistence — snapshots (immutable insert per push).
        try {
            $generatedAt = isset($validated['generated_at'])
                ? Carbon::parse($validated['generated_at'])
                : now();

            foreach ($periods as $days => $periodData) {
                PmtaStatsSnapshot::create([
                    'server' => $serverName,
                    'period_days' => (int) $days,
                    'snapshot_at' => $generatedAt,
                    'delivered' => $periodData['totals']['delivered'],
                    'bounced_stop' => $periodData['totals']['bounced_stop'],
                    'bounced_go' => $periodData['totals']['bounced_go'],
                    'domains' => $periodData['domains'],
                    'ips' => $periodData['ips'] ?? [],
                ]);
            }

            // 3) DB persistence — buckets (MAX-guarded upsert).
            foreach ($buckets as $bucketIso => $bucketData) {
                $bucketStart = Carbon::parse($bucketIso)->utc();

                DB::statement(
                    'INSERT INTO pmta_stats_buckets
                        (server, granularity, bucket_start, delivered, bounced_stop, bounced_go, domains, ips, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                        delivered    = GREATEST(delivered, VALUES(delivered)),
                        bounced_stop = GREATEST(bounced_stop, VALUES(bounced_stop)),
                        bounced_go   = GREATEST(bounced_go, VALUES(bounced_go)),
                        domains      = VALUES(domains),
                        ips          = VALUES(ips),
                        updated_at   = NOW()',
                    [
                        $serverName,
                        $granularity,
                        $bucketStart,
                        (int) $bucketData['totals']['delivered'],
                        (int) $bucketData['totals']['bounced_stop'],
                        (int) $bucketData['totals']['bounced_go'],
                        json_encode($bucketData['domains'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        json_encode($bucketData['ips'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::error('[pmta-stats] DB persistence failed', [
                'server' => $serverName,
                'err' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'DB persistence failed'], 500);
        }

        return response()->json(['result' => 'OK']);
    }
}
