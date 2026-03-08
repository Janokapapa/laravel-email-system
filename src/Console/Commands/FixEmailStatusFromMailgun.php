<?php

namespace JanDev\EmailSystem\Console\Commands;

use JanDev\EmailSystem\Models\EmailLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FixEmailStatusFromMailgun extends Command
{
    protected $signature = 'email-system:fix-status
                            {--date= : Date to process for fix mode (Y-m-d format, default: yesterday)}
                            {--backfill : Update sent → delivered for API-confirmed delivered emails}
                            {--date-from= : Start date for backfill (Y-m-d format)}
                            {--date-to= : End date for backfill (Y-m-d format, default: today)}
                            {--dry-run : Only show what would be fixed, do not update}';

    protected $description = 'Fix email status from Mailgun delivered events. Use --backfill to mark sent→delivered for confirmed deliveries.';

    public function handle(): int
    {
        if ($this->option('backfill')) {
            return $this->runBackfill();
        }

        return $this->runFix();
    }

    /**
     * Fix falsely failed emails that Mailgun confirmed as delivered.
     */
    private function runFix(): int
    {
        try {
            $date = $this->option('date')
                ? \Carbon\Carbon::parse($this->option('date'))->startOfDay()
                : now()->subDay()->startOfDay();
        } catch (\Exception $e) {
            $this->error('Invalid date format. Use Y-m-d format.');
            return Command::FAILURE;
        }

        $endDate = $date->copy()->addDay();
        $dryRun = $this->option('dry-run');

        $this->info("Fixing failed→delivered for: {$date->format('Y-m-d')}");
        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be made');
        }

        [$totalEvents, $totalFixed] = $this->processMailgunEvents(
            begin: $date->timestamp,
            end: $endDate->timestamp,
            targetStatus: 'failed',
            dryRun: $dryRun,
        );

        $this->newLine();
        $this->info("Done!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total delivered events', $totalEvents],
                ['Fixed (failed → delivered)', $totalFixed],
            ]
        );

        if ($dryRun && $totalFixed > 0) {
            $this->warn("Run without --dry-run to apply fixes");
        }

        return Command::SUCCESS;
    }

    /**
     * Backfill: mark sent→delivered for emails confirmed by Mailgun Events API.
     */
    private function runBackfill(): int
    {
        try {
            $dateFrom = $this->option('date-from')
                ? \Carbon\Carbon::parse($this->option('date-from'))->startOfDay()
                : now()->subDays(7)->startOfDay();

            $dateTo = $this->option('date-to')
                ? \Carbon\Carbon::parse($this->option('date-to'))->endOfDay()
                : now()->endOfDay();
        } catch (\Exception $e) {
            $this->error('Invalid date format. Use Y-m-d format.');
            return Command::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        $this->info("Backfilling sent→delivered from {$dateFrom->format('Y-m-d')} to {$dateTo->format('Y-m-d')}");
        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be made');
        }

        [$totalEvents, $totalFixed] = $this->processMailgunEvents(
            begin: $dateFrom->timestamp,
            end: $dateTo->timestamp,
            targetStatus: 'sent',
            dryRun: $dryRun,
        );

        $this->newLine();
        $this->info("Done!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total delivered events', $totalEvents],
                ['Updated (sent → delivered)', $totalFixed],
            ]
        );

        if ($dryRun && $totalFixed > 0) {
            $this->warn("Run without --dry-run to apply updates");
        }

        return Command::SUCCESS;
    }

    /**
     * Page through Mailgun delivered events and update matching EmailLog records.
     *
     * @return array{int, int} [totalEvents, totalFixed]
     */
    private function processMailgunEvents(int $begin, int $end, string $targetStatus, bool $dryRun): array
    {
        $apiKey   = config('email-system.mailgun.secret');
        $domain   = config('email-system.mailgun.domain');

        if (empty($apiKey) || empty($domain)) {
            $this->error('Mailgun API credentials not configured. Set email-system.mailgun.secret and email-system.mailgun.domain in config.');
            return [0, 0];
        }
        $endpoint = config('email-system.mailgun.endpoint', 'https://api.eu.mailgun.net');
        $baseUrl  = "{$endpoint}/v3/{$domain}/events";

        $totalEvents = 0;
        $totalFixed  = 0;

        $url = $baseUrl . '?' . http_build_query([
            'begin' => $begin,
            'end'   => $end,
            'event' => 'delivered',
            'limit' => 300,
        ]);

        do {
            $response = Http::withBasicAuth('api', $apiKey)
                ->timeout(60)
                ->get($url);

            if (!$response->successful()) {
                $this->error("API error: " . $response->body());
                return [$totalEvents, $totalFixed];
            }

            $data      = $response->json();
            $items     = $data['items'] ?? [];
            $pageCount = count($items);
            $totalEvents += $pageCount;

            $this->info("Processing page with {$pageCount} events (total: {$totalEvents})");

            foreach ($items as $event) {
                $messageId = $event['message']['headers']['message-id'] ?? null;
                $timestamp = $event['timestamp'] ?? null;

                if (!$messageId) {
                    continue;
                }

                $email = EmailLog::where('mailgun_message_id', $messageId)
                    ->where('status', $targetStatus)
                    ->first();

                if ($email) {
                    $totalFixed++;

                    if (!$dryRun) {
                        $deliveredAt = $timestamp
                            ? \Carbon\Carbon::createFromTimestamp($timestamp)
                            : now();

                        $email->update([
                            'status'        => 'delivered',
                            'delivered_at'  => $deliveredAt,
                            'bounce_type'   => null,
                            'bounce_reason' => null,
                            'bounced_at'    => null,
                        ]);
                    }

                    if ($totalFixed <= 20) {
                        $this->line("  Updated: {$email->recipient}");
                    } elseif ($totalFixed === 21) {
                        $this->line("  ... (showing first 20 only)");
                    }
                }
            }

            $url = $data['paging']['next'] ?? null;
            usleep(100000);

        } while ($url && $pageCount > 0);

        return [$totalEvents, $totalFixed];
    }
}
