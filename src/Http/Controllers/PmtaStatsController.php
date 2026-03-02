<?php

namespace JanDev\EmailSystem\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class PmtaStatsController extends Controller
{
    private const EXPECTED_DOMAIN_KEYS = ['Gmail', 'Microsoft', 'Yahoo', 'iCloud', 'Other'];

    private const ALLOWED_PERIODS = [1, 7, 14, 30];

    /**
     * Handle PMTA statistics push from each PMTA server.
     *
     * Called by /var/www/pmta/scripts/push-stats.py (hourly cron on each server).
     * Stores aggregated stats in cache for 2 hours per period. Dashboard reads from cache.
     * Auth: X-API-Key header must match config('email-system.pmta.bounce_api_key')
     * Response: {"result": "OK"}
     */
    public function handle(Request $request): \Illuminate\Http\JsonResponse
    {
        // Authenticate
        $expectedKey = config('email-system.pmta.bounce_api_key');
        $receivedKey = $request->header('X-API-Key');

        if (empty($expectedKey) || !is_string($receivedKey) || !hash_equals($expectedKey, $receivedKey)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Validate top-level structure
        $validated = $request->validate([
            'server' => 'required|string|max:50',
            'generated_at' => 'sometimes|date',
            'periods' => 'required|array',
        ]);

        // Reject unknown servers
        $allowedServers = config('email-system.pmta.servers', []);
        $serverName = $validated['server'];

        if (!in_array($serverName, $allowedServers, true)) {
            return response()->json(['error' => 'Unknown server'], 422);
        }

        // Validate each period
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

            // Validate totals
            if (!isset($periodData['totals']) || !is_array($periodData['totals'])) {
                return response()->json(['error' => "Period {$days}: totals required"], 422);
            }

            $totals = $periodData['totals'];
            foreach (['delivered', 'bounced_stop', 'bounced_go'] as $key) {
                if (!isset($totals[$key]) || !is_int($totals[$key]) || $totals[$key] < 0) {
                    return response()->json(['error' => "Period {$days}: totals.{$key} must be a non-negative integer"], 422);
                }
            }

            // Validate domains
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

            // Validate IPs (optional)
            if (isset($periodData['ips']) && is_array($periodData['ips'])) {
                foreach ($periodData['ips'] as $ip => $ipData) {
                    if (!is_array($ipData) || !isset($ipData['delivered'], $ipData['bounced'])) {
                        return response()->json(['error' => "Period {$days}: IP {$ip} must have delivered and bounced"], 422);
                    }
                }
            }
        }

        // Store each period in its own cache key
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

        return response()->json(['result' => 'OK']);
    }
}
