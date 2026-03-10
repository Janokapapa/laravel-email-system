<?php

namespace JanDev\EmailSystem\Console\Commands;

use JanDev\EmailSystem\Models\AudienceUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncCustomFieldIndexes extends Command
{
    protected $signature = 'audience:sync-custom-field-indexes';
    protected $description = 'Sync MySQL virtual generated columns and indexes for audience user custom fields';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->warn('This command requires MySQL. Current driver: ' . DB::getDriverName());
            return Command::SUCCESS;
        }

        $definitions = AudienceUser::getCustomFieldDefinitions();

        $definedSlugs = collect($definitions)
            ->filter(fn ($f) => isset($f['slug']) && preg_match('/^[a-zA-Z0-9_]+$/', $f['slug']))
            ->pluck('slug')
            ->all();

        // Find existing cf_*_idx columns using Schema (cross-DB) then filter
        $existingCfColumns = collect(Schema::getColumnListing('audience_users'))
            ->filter(fn ($col) => str_starts_with($col, 'cf_') && str_ends_with($col, '_idx'))
            ->values()
            ->all();

        // Add missing columns and indexes
        foreach ($definedSlugs as $slug) {
            $colName = "cf_{$slug}_idx";
            $idxName = "idx_cf_{$slug}";

            if (!Schema::hasColumn('audience_users', $colName)) {
                $this->line("  Adding column: {$colName}");
                DB::statement(
                    "ALTER TABLE audience_users ADD COLUMN `{$colName}` VARCHAR(500) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`custom_fields`, '$.{$slug}'))) VIRTUAL"
                );
                DB::statement("CREATE INDEX `{$idxName}` ON audience_users (`{$colName}`)");
                $this->info("  + Created column and index for: {$slug}");
            }
        }

        // Drop orphaned columns (no longer in definitions)
        $definedColNames = array_map(fn ($slug) => "cf_{$slug}_idx", $definedSlugs);
        $orphans = array_diff($existingCfColumns, $definedColNames);

        foreach ($orphans as $orphanCol) {
            $slug = preg_replace('/^cf_(.+)_idx$/', '$1', $orphanCol);
            $idxName = "idx_cf_{$slug}";

            $this->line("  Removing orphan column: {$orphanCol}");

            try {
                DB::statement("DROP INDEX `{$idxName}` ON audience_users");
            } catch (\Exception $e) {
                $this->line("  Note: Index {$idxName} did not exist or already dropped.");
            }

            DB::statement("ALTER TABLE audience_users DROP COLUMN `{$orphanCol}`");
            $this->info("  - Removed column and index for: {$orphanCol}");
        }

        $this->info('Custom field indexes synced successfully.');

        return Command::SUCCESS;
    }
}
