<?php

namespace JanDev\EmailSystem\Console\Commands;

use Illuminate\Console\Command;
use JanDev\EmailSystem\Models\PmtaStatsBucket;
use JanDev\EmailSystem\Models\PmtaStatsSnapshot;

class PrunePmtaStats extends Command
{
    protected $signature = 'pmta:prune-stats {--days= : Override retention days (default: config or 365)}';

    protected $description = 'Delete PMTA stats rows older than the retention window from buckets and snapshots';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('email-system.pmta.stats_retention_days', 365);

        if ($days < 0) {
            $this->error("Invalid --days value: {$days} (must be >= 0)");
            return Command::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $deletedBuckets = PmtaStatsBucket::where('bucket_start', '<', $cutoff)->delete();
        $deletedSnapshots = PmtaStatsSnapshot::where('snapshot_at', '<', $cutoff)->delete();

        $this->info(
            "Deleted {$deletedBuckets} buckets, {$deletedSnapshots} snapshots older than {$cutoff->toDateString()} ({$days} days)"
        );

        return Command::SUCCESS;
    }
}
