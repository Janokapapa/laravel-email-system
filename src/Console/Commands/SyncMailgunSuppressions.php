<?php

namespace JanDev\EmailSystem\Console\Commands;

use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\BouncedEmail;
use Illuminate\Console\Command;

class SyncMailgunSuppressions extends Command
{
    protected $signature = 'email-system:sync-suppressions {--dry-run : Show what would be synced without making changes} {--api-key= : Override API key}';
    protected $description = 'Sync Mailgun suppression list (bounces) with AudienceUser table';

    public function handle(): int
    {
        $domain = config('email-system.mailgun.domain');
        $apiKey = $this->option('api-key') ?: config('email-system.mailgun.secret');
        $endpoint = config('email-system.mailgun.endpoint', 'https://api.eu.mailgun.net');

        if (!$apiKey) {
            $this->error('No API key configured. Set MAILGUN_SECRET in .env or use --api-key option.');
            return Command::FAILURE;
        }

        $this->info("Fetching suppressions from Mailgun for domain: {$domain}");

        $dryRun = $this->option('dry-run');
        $synced = 0;
        $alreadyBounced = 0;
        $notFound = 0;

        try {
            $allBounces = [];
            $nextUrl = "{$endpoint}/v3/{$domain}/bounces?limit=1000";
            $client = new \GuzzleHttp\Client();

            $page = 1;
            while ($nextUrl) {
                $this->info("Fetching page {$page}...");

                $response = $client->get($nextUrl, [
                    'auth' => ['api', $apiKey],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                $items = $data['items'] ?? [];

                foreach ($items as $bounce) {
                    $allBounces[] = [
                        'email' => $bounce['address'],
                        'code' => $bounce['code'] ?? '',
                        'error' => $bounce['error'] ?? '',
                        'created_at' => $bounce['created_at'] ?? null,
                    ];
                }

                $this->info("  -> " . count($items) . " bounces (total: " . count($allBounces) . ")");

                $nextUrl = $data['paging']['next'] ?? null;

                if (empty($items) || count($items) < 1000) {
                    break;
                }

                $page++;
            }

            $this->info("Total bounces in Mailgun: " . count($allBounces));

            $bar = $this->output->createProgressBar(count($allBounces));
            $bar->start();

            foreach ($allBounces as $bounce) {
                $email = strtolower($bounce['email']);
                $bounceReason = trim("[{$bounce['code']}] {$bounce['error']}");
                $bouncedAt = $bounce['created_at'] ? new \DateTime($bounce['created_at']) : now();

                // Check if already in bounce registry
                if (BouncedEmail::where('email', $email)->exists()) {
                    $alreadyBounced++;
                    $bar->advance();
                    continue;
                }

                // Check if any audience_user row exists for this email
                $existsInAudience = AudienceUser::where('email', $email)->exists();
                if (!$existsInAudience) {
                    $notFound++;
                    // Still write to bounce registry so future imports are blocked
                    if (!$dryRun) {
                        BouncedEmail::updateOrCreate(
                            ['email' => $email],
                            [
                                'bounce_type'  => 'hard',
                                'bounce_reason' => $bounceReason,
                                'source'       => 'mailgun',
                                'bounced_at'   => $bouncedAt,
                            ]
                        );
                    }
                    $bar->advance();
                    continue;
                }

                if (!$dryRun) {
                    // Mass-update ALL rows for this email (many-to-many support)
                    AudienceUser::where('email', $email)->update([
                        'is_active'    => false,
                        'bounced'      => true,
                        'bounce_type'  => 'hard',
                        'bounce_reason' => $bounceReason,
                        'bounced_at'   => $bouncedAt,
                        'zerobounce_status' => 'bounced',
                    ]);
                    // Write to global bounce registry
                    BouncedEmail::updateOrCreate(
                        ['email' => $email],
                        [
                            'bounce_type'  => 'hard',
                            'bounce_reason' => $bounceReason,
                            'source'       => 'mailgun',
                            'bounced_at'   => $bouncedAt,
                        ]
                    );
                }
                $synced++;
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            $this->info("Results:");
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total in Mailgun', count($allBounces)],
                    ['Already bounced in DB', $alreadyBounced],
                    ['Not in AudienceUser', $notFound],
                    ['Synced (new bounces)', $synced],
                ]
            );

            if ($dryRun) {
                $this->warn("DRY RUN - no changes made. Run without --dry-run to apply changes.");
            }

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
