<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use JanDev\EmailSystem\Jobs\MergeAudiencesJob;

return new class extends Migration
{
    // MergeAudiencesJob + JSON_SET don't need transaction wrapping
    public $withinTransaction = false;

    // Expected group names and IDs — migration aborts if mismatch
    private const EXPECTED = [
        '7goldcasino depositors'       => 156,
        'aztecparadise depositors'      => 157,
        'casino007 depositors'          => 158,
        'cryptocom depositors'          => 159,
        'luckybarcasino depositors'     => 160,
        'mobilecasino888 depositors'    => 161,
        'mrjonescasino depositors'      => 162,
        'sunclubcasino depositors'      => 163,
        'mrjonescasino non-depositors'  => 164,
        'megawaysvip depositors'        => 165,
    ];

    // Depositor list IDs — group 162 (mrjonescasino) is EXCLUDED because
    // it contains both depositors AND moved non-depositors (with their own depositor flags set in steps 1-2)
    private const DEPOSITOR_IDS = [156, 157, 158, 159, 160, 161, 163, 165];

    public function up(): void
    {
        // ── Safety assertions: verify groups exist by ID ──────────────
        // Accept both old names ("X depositors") and already-renamed slugs
        $requiredIds = [156, 157, 158, 159, 160, 161, 162, 163, 165];
        foreach ($requiredIds as $id) {
            $exists = DB::table('email_audience_groups')->where('id', $id)->exists();
            if (!$exists) {
                throw new \RuntimeException("Migration aborted: audience group ID {$id} not found.");
            }
        }

        // ── 1. Set depositor = true on mrjonescasino depositors (id=162) ────
        DB::statement(
            "UPDATE audience_users
             SET custom_fields = JSON_SET(COALESCE(custom_fields, '{}'), '$.depositor', CAST('true' AS JSON))
             WHERE email_audience_group_id = 162"
        );

        // ── 2-3. Merge non-depositors (164) into depositors (162) if 164 exists ──
        $group164 = DB::table('email_audience_groups')->where('id', 164)->exists();
        if ($group164) {
            DB::statement(
                "UPDATE audience_users
                 SET custom_fields = JSON_SET(COALESCE(custom_fields, '{}'), '$.depositor', CAST('false' AS JSON))
                 WHERE email_audience_group_id = 164"
            );

            MergeAudiencesJob::dispatchSync(
                sourceIds: [164],
                targetId: 162,
                deleteSources: true,
            );
        }

        // ── 4-5. Rename groups to slugs (idempotent) ────────────────────────
        $renames = [162 => 'mrjonescasino', 156 => '7goldcasino'];
        foreach ($renames as $id => $slug) {
            DB::table('email_audience_groups')
                ->where('id', $id)
                ->where('name', '!=', $slug)
                ->update(['name' => $slug]);
        }

        // ── 6. Set depositor = true on all depositor lists ─────────────
        $depositorIds = implode(',', self::DEPOSITOR_IDS);
        DB::statement(
            "UPDATE audience_users
             SET custom_fields = JSON_SET(COALESCE(custom_fields, '{}'), '$.depositor', CAST('true' AS JSON))
             WHERE email_audience_group_id IN ({$depositorIds})"
        );
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'This data migration (merge + depositor field assignment) cannot be fully reversed. '
            . 'Manual intervention required to split merged groups and clear depositor fields.'
        );
    }
};
