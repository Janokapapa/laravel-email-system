<?php

namespace JanDev\EmailSystem\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PmtaBounceCountersController extends Controller
{
    /**
     * Receive aggregated per-(server, bounce_cat, hour) bounce counters from
     * process-bounces.py and atomically increment them in pmta_bounce_counters.
     *
     * Payload:
     *   {
     *     "server": "caspmta1",
     *     "counters": [
     *       {"bounce_cat": "stop", "counter_hour": "2026-05-14 14:00:00", "count": 3},
     *       {"bounce_cat": "go",   "counter_hour": "2026-05-14 14:00:00", "count": 12}
     *     ]
     *   }
     *
     * Auth: X-API-Key (shared with PmtaBounceController).
     */
    public function handle(Request $request)
    {
        $expectedKey = config('email-system.pmta.bounce_api_key');
        $receivedKey = $request->header('X-API-Key');

        if (empty($expectedKey) || !is_string($receivedKey) || !hash_equals($expectedKey, $receivedKey)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $server = trim((string) $request->input('server', ''));
        if ($server === '') {
            return response()->json(['error' => 'Missing server'], 400);
        }

        $allowedServers = config('email-system.pmta.servers', []);
        if (!in_array($server, $allowedServers, true)) {
            return response()->json(['error' => 'Invalid server'], 400);
        }

        $counters = $request->input('counters', []);
        if (!is_array($counters)) {
            return response()->json(['error' => 'counters must be an array'], 400);
        }

        $allowedCats = ['stop', 'go', 'unknown'];

        // Pre-validate every entry so a partial batch never reaches the DB.
        // (Previously a mid-loop 400 left earlier rows already-upserted, causing
        // double-count on the caller's retry.)
        $prepared = [];
        foreach ($counters as $c) {
            if (!is_array($c)
                || !isset($c['bounce_cat'], $c['counter_hour'], $c['count'])
                || !in_array($c['bounce_cat'], $allowedCats, true)
            ) {
                return response()->json(['error' => 'Invalid counter entry'], 400);
            }

            try {
                $hour = Carbon::parse($c['counter_hour'])->startOfHour();
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Invalid counter_hour'], 400);
            }

            $count = (int) $c['count'];
            if ($count < 0) {
                return response()->json(['error' => 'Negative count not allowed'], 400);
            }

            $prepared[] = [$server, $c['bounce_cat'], $hour->format('Y-m-d H:i:s'), $count];
        }

        if (!empty($prepared)) {
            // Single multi-row INSERT ... AS new ON DUPLICATE KEY UPDATE — one
            // round-trip, atomic. MySQL 8.0.20+ alias syntax (forward-compat).
            $placeholders = implode(',', array_fill(0, count($prepared), '(?, ?, ?, ?, NOW(), NOW())'));
            $bindings = array_merge(...array_map(fn ($row) => $row, $prepared));

            DB::statement(
                "INSERT INTO pmta_bounce_counters
                    (server, bounce_cat, counter_hour, count, created_at, updated_at)
                 VALUES {$placeholders} AS new
                 ON DUPLICATE KEY UPDATE
                    count = pmta_bounce_counters.count + new.count,
                    updated_at = NOW()",
                $bindings
            );
        }

        return response()->json([
            'result' => 'OK',
            'applied' => count($prepared),
        ]);
    }
}
