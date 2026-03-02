<?php

namespace JanDev\EmailSystem\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class PmtaStatsController extends Controller
{
    private const EXPECTED_DOMAIN_KEYS = ['Gmail', 'Microsoft', 'Yahoo', 'iCloud', 'Other'];

    /**
     * Handle PMTA statistics push from each PMTA server.
     *
     * Called by /var/www/pmta/scripts/push-stats.py (hourly cron on each server).
     * Stores aggregated stats in cache for 2 hours. Dashboard reads from cache.
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

        // Validate
        $validated = $request->validate([
            'server' => 'required|string|max:50',
            'period_days' => 'sometimes|integer|min:1|max:365',
            'generated_at' => 'sometimes|date',
            'totals' => 'required|array',
            'totals.delivered' => 'required|integer|min:0',
            'totals.bounced_stop' => 'required|integer|min:0',
            'totals.bounced_go' => 'required|integer|min:0',
            'domains' => 'required|array',
            'domains.Gmail' => 'required|array',
            'domains.Microsoft' => 'required|array',
            'domains.Yahoo' => 'required|array',
            'domains.iCloud' => 'required|array',
            'domains.Other' => 'required|array',
            'domains.*.delivered' => 'required|integer|min:0',
            'domains.*.bounced' => 'required|integer|min:0',
        ]);

        // Reject extra domain keys
        $domainKeys = array_keys($validated['domains']);
        sort($domainKeys);
        $expectedKeys = self::EXPECTED_DOMAIN_KEYS;
        sort($expectedKeys);

        if ($domainKeys !== $expectedKeys) {
            return response()->json(['error' => 'Invalid domain keys'], 422);
        }

        // Reject unknown servers
        $allowedServers = config('email-system.pmta.servers', []);
        $serverName = $validated['server'];

        if (!in_array($serverName, $allowedServers, true)) {
            return response()->json(['error' => 'Unknown server'], 422);
        }

        $cacheKey = "pmta_stats:{$serverName}";

        Cache::put($cacheKey, [
            'server' => $serverName,
            'period_days' => $validated['period_days'] ?? 7,
            'generated_at' => $validated['generated_at'] ?? null,
            'totals' => $validated['totals'],
            'domains' => $validated['domains'],
        ], now()->addHours(2));

        return response()->json(['result' => 'OK']);
    }
}
