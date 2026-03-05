<?php

namespace JanDev\EmailSystem\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportOemproSuppressions extends Command
{
    protected $signature = 'email:import-oempro-suppressions
        {database : OemPro database name (mail_newcasproms_com, einformations, gamblingmedia)}
        {--dry-run : Show what would be imported without inserting}';

    protected $description = 'Import OemPro suppression list into bounced_emails table';

    private const VALID_DBS = ['mail_newcasproms_com', 'einformations', 'gamblingmedia'];

    private const SOURCE_MAP = [
        'Hard Bounced'  => 'hard',
        'SPAM complaint' => 'spam',
    ];

    public function handle(): int
    {
        $database = $this->argument('database');
        $dryRun = (bool) $this->option('dry-run');

        if (!in_array($database, self::VALID_DBS)) {
            $this->error("Invalid database. Must be one of: " . implode(', ', self::VALID_DBS));
            return 1;
        }

        $this->info("Importing OemPro suppressions from [{$database}]" . ($dryRun ? ' (DRY RUN)' : ''));

        try {
            if (!$this->openTunnel()) {
                return 1;
            }
            $oemDb = $this->getOemproDB($database);

            $imported = 0;
            $skipped = 0;
            $now = now()->toDateTimeString();

            $oemDb->table('oempro_suppression_list')
                ->orderBy('EmailAddress')
                ->chunk(1000, function ($suppressions) use ($dryRun, &$imported, &$skipped, $now) {
                    $bouncedRows = [];

                    foreach ($suppressions as $row) {
                        $bounceType = self::SOURCE_MAP[$row->SuppressionSource] ?? null;

                        if (!$bounceType) {
                            $skipped++;
                            continue;
                        }

                        $email = strtolower(trim($row->EmailAddress));
                        $bouncedRows[] = [
                            'email'         => $email,
                            'bounce_type'   => $bounceType,
                            'bounce_reason' => 'OemPro suppression: ' . ($row->SuppressionSource ?? ''),
                            'source'        => 'oempro',
                            'bounced_at'    => $now,
                            'created_at'    => $now,
                            'updated_at'    => $now,
                        ];

                        $imported++;
                    }

                    if (!$dryRun && !empty($bouncedRows)) {
                        // Bulk upsert bounced_emails
                        DB::table('bounced_emails')->upsert(
                            $bouncedRows,
                            ['email'],
                            ['bounce_type', 'bounce_reason', 'source', 'bounced_at', 'updated_at']
                        );

                        // Bulk mark audience_users as bounced
                        $emails = array_column($bouncedRows, 'email');
                        DB::table('audience_users')
                            ->whereIn('email', $emails)
                            ->where('bounced', false)
                            ->update([
                                'bounced'    => true,
                                'is_active'  => false,
                                'bounce_type' => 'hard',
                                'bounced_at' => $now,
                            ]);
                    }

                    $this->line("  Processed {$imported} suppressions...");
                });

            $this->info("Done. Imported: {$imported} | Skipped (manual unsubscribes): {$skipped}");
            return 0;

        } finally {
            $this->closeTunnel();
        }
    }

    public function openTunnel(): bool
    {
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        // Kill existing socket (idempotent)
        $killProcess = @proc_open(['ssh', '-S', '/tmp/oempro-tunnel', '-O', 'exit', 'caspmta5'], $descriptor, $pipes);
        if (is_resource($killProcess)) {
            proc_close($killProcess);
        }

        // Open new tunnel
        $process = proc_open(
            ['ssh', '-S', '/tmp/oempro-tunnel', '-M', '-f', '-N',
                '-L', '33061:localhost:3306', 'caspmta5'],
            $descriptor,
            $pipes
        );

        if (!is_resource($process)) {
            $this->error("Failed to open SSH tunnel process");
            return false;
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $this->error("Failed to open SSH tunnel (exit code: {$exitCode})");
            return false;
        }

        sleep(1);
        return true;
    }

    public function closeTunnel(): void
    {
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(
            ['ssh', '-S', '/tmp/oempro-tunnel', '-O', 'exit', 'caspmta5'],
            $descriptor,
            $pipes
        );
        if (is_resource($process)) {
            proc_close($process);
        }
    }

    public function getOemproDB(string $database): \Illuminate\Database\Connection
    {
        $config = config('email-system.oempro_db');

        config([
            'database.connections.oempro' => [
                'driver'    => 'mysql',
                'host'      => $config['host'],
                'port'      => $config['port'],
                'database'  => $database,
                'username'  => $config['username'],
                'password'  => $config['password'],
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ],
        ]);

        DB::purge('oempro');
        return DB::connection('oempro');
    }

    public function mapBounceType(string $source): ?string
    {
        return self::SOURCE_MAP[$source] ?? null;
    }
}
