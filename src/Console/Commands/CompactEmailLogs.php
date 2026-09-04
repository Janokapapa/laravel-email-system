<?php

namespace JanDev\EmailSystem\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Empties the bulky text columns of old email_logs rows while keeping every
 * statistic. The body of a six month old newsletter is never read again, but it
 * is what makes the table grow by gigabytes a month - on the parkfly live
 * database the message column alone averaged ~12 KB per row.
 *
 * The row itself is kept: campaign reports (open rate, click rate, bounce
 * statistics per campaign) count rows and read event timestamps, so deleting
 * would silently change historical numbers. Compacting does not.
 */
class CompactEmailLogs extends Command
{
    protected $signature = 'email-system:compact-logs
        {--days= : Rows older than this many days are compacted (default: config, 90)}
        {--chunk= : Rows per batch (default: config, 2000)}
        {--sleep= : Seconds to pause between batches (default: config, 1)}
        {--limit=0 : Stop after this many rows (0 = no limit). Use it to work the backlog off in slices.}
        {--measure : Also report how many bytes of text were freed (extra read per batch, slower)}
        {--dry-run : Report what would happen, change nothing}';

    protected $description = 'Compact old email logs: drop message/error/bounce_reason, keep all statistics';

    /** Columns emptied by the compaction. Everything else is a statistic and stays. */
    private const TEXT_COLUMNS = ['message', 'error', 'bounce_reason'];

    public function handle(): int
    {
        if (!Schema::hasColumn('email_logs', 'compacted_at')) {
            $this->error('email_logs.compacted_at is missing - run the package migrations first.');

            return Command::FAILURE;
        }

        $days = (int) ($this->option('days') ?? config('email-system.log_compaction.days', 90));
        $chunk = (int) ($this->option('chunk') ?? config('email-system.log_compaction.chunk', 2000));
        $sleep = (float) ($this->option('sleep') ?? config('email-system.log_compaction.sleep', 1));
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 1 || $chunk < 1) {
            $this->error("Invalid options: --days={$days}, --chunk={$chunk} (both must be >= 1)");

            return Command::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $this->info(sprintf(
            '%sCompacting email_logs older than %s (%d days), %d rows per batch.',
            $dryRun ? '[dry-run] ' : '',
            $cutoff->toDateTimeString(),
            $days,
            $chunk
        ));

        $pending = $this->pendingQuery($cutoff)->count();
        $this->line("Pending rows: {$pending}");

        if ($pending === 0) {
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->line('Dry run, nothing was changed.');

            return Command::SUCCESS;
        }

        $done = 0;
        $freed = 0;
        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, $pending) : $pending);
        $bar->start();

        while (true) {
            $take = $limit > 0 ? min($chunk, $limit - $done) : $chunk;
            if ($take < 1) {
                break;
            }

            // Ids first, then a keyed update: the select touches the index only,
            // and the write locks a small, known set of rows instead of an open
            // ended range.
            $ids = $this->pendingQuery($cutoff)->orderBy('id')->limit($take)->pluck('id')->all();
            if ($ids === []) {
                break;
            }

            if ($this->option('measure')) {
                $freed += (int) DB::table('email_logs')->whereIn('id', $ids)->sum(
                    DB::raw('COALESCE(LENGTH(message),0) + COALESCE(LENGTH(error),0) + COALESCE(LENGTH(bounce_reason),0)')
                );
            }

            DB::table('email_logs')->whereIn('id', $ids)->update(
                array_fill_keys(self::TEXT_COLUMNS, null) + ['compacted_at' => now()]
            );

            $done += count($ids);
            $bar->advance(count($ids));

            if ($sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Compacted {$done} rows.");
        if ($this->option('measure')) {
            $this->line('Text freed: ' . round($freed / 1024 / 1024, 1) . ' MB (disk is only returned by a table rebuild)');
        }
        if ($this->pendingQuery($cutoff)->exists()) {
            $this->warn('Backlog remains - run the command again (or raise --limit).');
        }

        return Command::SUCCESS;
    }

    /**
     * Every old row is claimed, including ones that never had a body. Marking
     * them too is what keeps the daily run cheap: it never looks at them again.
     */
    private function pendingQuery($cutoff)
    {
        return DB::table('email_logs')
            ->whereNull('compacted_at')
            ->where('created_at', '<', $cutoff);
    }
}
