<?php

namespace JanDev\EmailSystem\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JanDev\EmailSystem\Models\BouncedEmail;
use JanDev\EmailSystem\Models\PmtaBounceCounter;
use JanDev\EmailSystem\Services\TelegramNotifier;

class PmtaBounceSummary extends Command
{
    protected $signature = 'email:pmta-bounce-summary
        {--hours= : Override the window in hours (skips cached last_run_at)}
        {--force : Send a message even if there were zero bounces (watchdog mode)}
        {--dry-run : Print the message to stdout instead of sending to Telegram}';

    protected $description = 'Aggregate PMTA bounces from the last summary window and notify Telegram.';

    private const CACHE_KEY = 'pmta_bounce_summary:last_run_at';

    public function handle(TelegramNotifier $notifier): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $hoursOverride = $this->option('hours');

        // Pre-call config check (skip silent attempts in production logs).
        if (!$dryRun && !$notifier->isConfigured()) {
            Log::warning('Telegram not configured — skipping bounce summary send');
            return self::SUCCESS;
        }

        // Window resolution: cached last_run_at takes precedence, fallback to 6h.
        if ($hoursOverride !== null && $hoursOverride !== '') {
            $since = now()->subHours((int) $hoursOverride);
        } else {
            $cached = Cache::get(self::CACHE_KEY);
            $since = $cached ? Carbon::parse($cached) : now()->subHours(6);
        }

        // Hard bounces: group by pmta_server (NULL → "(unknown)").
        $hardPerServer = BouncedEmail::query()
            ->where('source', 'pmta')
            ->where('bounced_at', '>=', $since)
            ->selectRaw('COALESCE(pmta_server, "(unknown server)") as server, COUNT(*) as cnt')
            ->groupBy(DB::raw('COALESCE(pmta_server, "(unknown server)")'))
            ->orderByDesc('cnt')
            ->get();

        $hardTotal = (int) $hardPerServer->sum('cnt');

        $hardSamples = BouncedEmail::query()
            ->where('source', 'pmta')
            ->where('bounced_at', '>=', $since)
            ->orderByDesc('bounced_at')
            ->limit(5)
            ->get(['email', 'bounce_reason', 'source_domain']);

        // GO + STOP counters: group by server, bounce_cat.
        $counters = PmtaBounceCounter::query()
            ->where('counter_hour', '>=', $since)
            ->selectRaw('server, bounce_cat, SUM(count) as total')
            ->groupBy('server', 'bounce_cat')
            ->get();

        $goPerServer = $counters->where('bounce_cat', 'go')->pluck('total', 'server');
        $stopPerServerCounter = $counters->where('bounce_cat', 'stop')->pluck('total', 'server');

        $goTotal = (int) $goPerServer->sum();
        $counterTotal = (int) $counters->sum('total');

        // Watchdog: 0 hard + 0 counter rows + --force → send a warning instead of summary.
        if ($hardTotal === 0 && $counterTotal === 0) {
            if (!$force) {
                return self::SUCCESS;
            }
            $message = $this->buildWatchdogMessage($since);
        } else {
            $message = $this->buildSummaryMessage(
                $since,
                $hardTotal,
                $hardPerServer,
                $hardSamples,
                $goTotal,
                $goPerServer,
                $stopPerServerCounter,
            );
        }

        if ($dryRun) {
            $this->line($message);
            return self::SUCCESS;
        }

        $ok = $notifier->send($message);

        if ($ok) {
            Cache::put(self::CACHE_KEY, now()->toIso8601String(), 86400 * 7);
            return self::SUCCESS;
        }

        $this->error('Failed to send Telegram summary');
        return self::FAILURE;
    }

    private function buildSummaryMessage(
        Carbon $since,
        int $hardTotal,
        Collection $hardPerServer,
        Collection $hardSamples,
        int $goTotal,
        Collection $goPerServer,
        Collection $stopPerServerCounter,
    ): string {
        $lines = [];
        $lines[] = '📧 <b>Bounce Summary (since ' . $since->format('Y-m-d H:i') . ')</b>';
        $lines[] = '━━━━━━━━━━━━━━━━━━━━━━';
        $lines[] = '';

        $lines[] = '🔴 <b>Hard bounces: ' . $hardTotal . '</b>';
        foreach ($hardPerServer as $row) {
            $lines[] = '• ' . htmlspecialchars((string) $row->server) . ': ' . (int) $row->cnt;
        }

        $lines[] = '';
        $lines[] = '🟡 <b>Soft/GO bounces: ' . $goTotal . '</b>';
        foreach ($goPerServer as $server => $total) {
            $lines[] = '• ' . htmlspecialchars((string) $server) . ': ' . (int) $total;
        }

        if ($stopPerServerCounter->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<i>(Counter-based STOP, cross-check):</i>';
            foreach ($stopPerServerCounter as $server => $total) {
                $lines[] = '• ' . htmlspecialchars((string) $server) . ': ' . (int) $total;
            }
        }

        if ($hardSamples->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>Recent hard bounces:</b>';
            foreach ($hardSamples as $b) {
                $reason = $b->bounce_reason ? ' (' . htmlspecialchars((string) $b->bounce_reason) . ')' : '';
                $dom = $b->source_domain ? ' [' . htmlspecialchars((string) $b->source_domain) . ']' : '';
                $lines[] = '• ' . htmlspecialchars((string) $b->email) . $reason . $dom;
            }
        }

        $adminUrl = trim((string) config('email-system.telegram.admin_url', ''));
        if ($adminUrl !== '') {
            $lines[] = '';
            $escapedUrl = htmlspecialchars(rtrim($adminUrl, '/') . '/bounced-emails', ENT_QUOTES, 'UTF-8');
            $lines[] = '🔗 <a href="' . $escapedUrl . '">Részletek</a>';
        }

        return implode("\n", $lines);
    }

    /** Watchdog message — used when --force is set but no bounce data exists. */
    private function buildWatchdogMessage(Carbon $since): string
    {
        $lines = [];
        $lines[] = '⚠️ <b>Possible bounce processor failure</b>';
        $lines[] = '━━━━━━━━━━━━━━━━━━━━━━';
        $lines[] = '';
        $lines[] = 'No counter data received from any PMTA server since ' . $since->format('Y-m-d H:i') . '.';
        $lines[] = 'Check /var/log/pmta/bounce_processor.log on each server.';

        $adminUrl = trim((string) config('email-system.telegram.admin_url', ''));
        if ($adminUrl !== '') {
            $lines[] = '';
            $escapedUrl = htmlspecialchars(rtrim($adminUrl, '/') . '/bounced-emails', ENT_QUOTES, 'UTF-8');
            $lines[] = '🔗 <a href="' . $escapedUrl . '">Admin</a>';
        }

        return implode("\n", $lines);
    }
}
