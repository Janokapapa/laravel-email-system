<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const RENAMES = [
        157 => ['from' => 'aztecparadise depositors',      'to' => 'aztecparadise'],
        158 => ['from' => 'casino007 depositors',           'to' => 'casino007'],
        159 => ['from' => 'cryptocom depositors',           'to' => 'cryptocom'],
        160 => ['from' => 'luckybarcasino depositors',      'to' => 'luckybarcasino'],
        161 => ['from' => 'mobilecasino888 depositors',     'to' => 'mobilecasino888'],
        163 => ['from' => 'sunclubcasino depositors',       'to' => 'sunclubcasino'],
        165 => ['from' => 'megawaysvip depositors',         'to' => 'megawaysvip'],
    ];

    public function up(): void
    {
        // In testing environments, the production group IDs don't exist — skip this migration.
        if (app()->environment('testing')) {
            return;
        }

        foreach (self::RENAMES as $id => $names) {
            $group = DB::table('email_audience_groups')->where('id', $id)->first();

            if (!$group) {
                throw new \RuntimeException("Migration aborted: audience group ID {$id} not found.");
            }
            if ($group->name !== $names['from']) {
                // Already renamed or different name — skip
                if ($group->name === $names['to']) {
                    continue;
                }
                throw new \RuntimeException(
                    "Migration aborted: group {$id} expected name '{$names['from']}', got '{$group->name}'."
                );
            }

            DB::table('email_audience_groups')
                ->where('id', $id)
                ->update(['name' => $names['to']]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $id => $names) {
            DB::table('email_audience_groups')
                ->where('id', $id)
                ->where('name', $names['to'])
                ->update(['name' => $names['from']]);
        }
    }
};
