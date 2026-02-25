<?php

namespace JanDev\EmailSystem\Console\Commands;

use JanDev\EmailSystem\Models\EmailLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FixEmailStatusFromMailgun extends Command
{
    protected $signature = 'email-system:fix-status
                            {--date= : Date to process (Y-m-d format, default: yesterday)}
                            {--dry-run : Only show what would be fixed, do not update}';

    protected $description = 'Fix email status from Mailgun delivered events';

    public function handle(): int
    {
        $date = $this->option('date')
            ? \Carbon\Carbon::parse($this->option('date'))->startOfDay()
            : now()->subDay()->startOfDay();

        $endDate = $date->copy()->addDay();
        $dryRun = $this->option('dry-run');

        $this->info("Processing delivered events for: {$date->format('Y-m-d')}");
        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be made');
        }

        $apiKey = config('email-system.mailgun.secret');
        $domain = config('email-system.mailgun.domain');
        $endpoint = config('email-system.mailgun.endpoint', 'https://api.eu.mailgun.net');
        $baseUrl = "{$endpoint}/v3/{$domain}/events";

        $totalDelivered = 0;
        $totalFixed = 0;

        $params = [
            'begin' => $date->timestamp,
            'end' => $endDate->timestamp,
            'event' => 'delivered',
            'limit' => 300,
        ];

        $url = $baseUrl . '?' . http_build_query($params);

        do {
            $response = Http::withBasicAuth('api', $apiKey)
                ->timeout(60)
                ->get($url);

            if (!$response->successful()) {
                $this->error("API error: " . $response->body());
                return Command::FAILURE;
            }

            $data = $response->json();
            $items = $data['items'] ?? [];
            $pageCount = count($items);
            $totalDelivered += $pageCount;

            $this->info("Processing page with {$pageCount} events (total: {$totalDelivered})");

            foreach ($items as $event) {
                $messageId = $event['message']['headers']['message-id'] ?? null;

                if (!$messageId) {
                    continue;
                }

                $email = EmailLog::where('mailgun_message_id', $messageId)
                    ->where('status', 'failed')
                    ->first();

                if ($email) {
                    $totalFixed++;

                    if (!$dryRun) {
                        $email->update([
                            'status' => 'sent',
                            'bounce_type' => null,
                            'bounce_reason' => null,
                            'bounced_at' => null,
                        ]);
                    }

                    if ($totalFixed <= 20) {
                        $this->line("  Fixed: {$email->recipient}");
                    } elseif ($totalFixed == 21) {
                        $this->line("  ... (showing first 20 only)");
                    }
                }
            }

            $url = $data['paging']['next'] ?? null;
            usleep(100000);

        } while ($url && $pageCount > 0);

        $this->newLine();
        $this->info("Done!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total delivered events', $totalDelivered],
                ['Fixed (failed → sent)', $totalFixed],
            ]
        );

        if ($dryRun && $totalFixed > 0) {
            $this->warn("Run without --dry-run to apply fixes");
        }

        return Command::SUCCESS;
    }
}
