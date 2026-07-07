<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill first_sent_at for every sending domain that has ALREADY sent, using
 * its earliest email_logs row. Without this, established domains would have no
 * email_sending_domains record and the warmup limiter would treat them as
 * brand-new (day-0, cap 50/day) on the first send after deploy — throttling
 * live production traffic. After the backfill their first_sent_at is far in the
 * past, so the warmup curve is already complete (no cap); only genuinely new
 * domains start at the day-0 cap.
 *
 * Idempotent: updateOrInsert keyed on domain. Sets first_sent_at to the true
 * historical earliest send.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_sending_domains') || !Schema::hasTable('email_logs')) {
            return;
        }

        // Group email_logs by the From-address domain, take the earliest created_at.
        // (created_at is the send/queue time; sent_at is new and null for history.)
        $rows = DB::table('email_logs')
            ->selectRaw("LOWER(SUBSTRING_INDEX(sender, '@', -1)) as sending_domain, MIN(created_at) as first_sent_at")
            ->whereNotNull('sender')
            ->where('sender', 'like', '%@%')
            ->groupBy('sending_domain')
            ->get();

        $now = now();
        foreach ($rows as $r) {
            $domain = trim((string) $r->sending_domain);
            if ($domain === '' || $r->first_sent_at === null) {
                continue;
            }

            DB::table('email_sending_domains')->updateOrInsert(
                ['domain' => $domain],
                [
                    'first_sent_at'  => $r->first_sent_at,
                    'warmup_enabled' => true,
                    'updated_at'     => $now,
                    'created_at'     => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        // Non-destructive: leave the backfilled rows in place.
    }
};
