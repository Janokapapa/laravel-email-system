<?php

namespace JanDev\EmailSystem\Console\Commands;

use JanDev\EmailSystem\Models\EmailLog;
use Illuminate\Console\Command;

use function JanDev\EmailSystem\resolve_callback;

class CleanupMailgunEvents extends Command
{
    protected $signature = 'email-system:cleanup-events {--days=7 : Number of days to keep}';

    protected $description = 'Delete old email log entries (failed/skipped older than retention period)';

    public function handle()
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        // Check if a custom cleanup callback is configured
        $cleanupCallback = resolve_callback(config('email-system.cleanup_events_callback'));
        if ($cleanupCallback) {
            $deleted = $cleanupCallback($days, $cutoff);
            $this->info("Custom cleanup completed: {$deleted} records removed.");
            return Command::SUCCESS;
        }

        // Default: clean old failed/skipped email_logs entries
        $deleted = EmailLog::where('created_at', '<', $cutoff)
            ->whereIn('status', ['failed', 'skipped'])
            ->delete();

        $this->info("Deleted {$deleted} old email log entries (older than {$days} days).");

        return Command::SUCCESS;
    }
}
