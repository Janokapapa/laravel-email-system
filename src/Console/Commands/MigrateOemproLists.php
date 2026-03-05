<?php

namespace JanDev\EmailSystem\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateOemproLists extends Command
{
    protected $signature = 'email:migrate-oempro
        {database : OemPro database name (mail_newcasproms_com, einformations, gamblingmedia)}
        {--list= : Import only a specific ListID}
        {--dry-run : Show what would be imported without inserting}';

    protected $description = 'Migrate OemPro subscriber lists to audience groups via SSH tunnel to caspmta5';

    private const VALID_DBS = ['mail_newcasproms_com', 'einformations', 'gamblingmedia'];

    private const DB_PREFIXES = [
        'mail_newcasproms_com' => 'ncp_',
        'gamblingmedia'        => 'gmb_',
        'einformations'        => 'einf_',
    ];

    public function handle(): int
    {
        $database = $this->argument('database');
        $specificList = $this->option('list');
        $dryRun = (bool) $this->option('dry-run');

        if (!in_array($database, self::VALID_DBS)) {
            $this->error("Invalid database. Must be one of: " . implode(', ', self::VALID_DBS));
            return 1;
        }

        $this->info("Starting OemPro migration from [{$database}]" . ($dryRun ? ' (DRY RUN)' : ''));

        try {
            if (!$this->openTunnel()) {
                return 1;
            }
            $oemDb = $this->getOemproDB($database);

            // Detect charset and set connection accordingly
            $this->detectAndSetCharset($oemDb, $database);

            // Fetch lists
            $listsQuery = $oemDb->table('oempro_subscriber_lists');
            if ($specificList) {
                $listsQuery->where('ListID', (int) $specificList);
            }
            $lists = $listsQuery->get();

            if ($lists->isEmpty()) {
                $this->warn("No lists found in [{$database}]");
                return 0;
            }

            $this->info("Found {$lists->count()} list(s) to process");

            foreach ($lists as $list) {
                $this->processList($oemDb, $database, $list, $dryRun);
            }

            $this->info("Migration complete.");
            return 0;

        } finally {
            $this->closeTunnel();
        }
    }

    /**
     * Open SSH ControlMaster tunnel to caspmta5.
     * Socket is /tmp/oempro-tunnel.
     * Uses -o options via array to avoid shell injection.
     */
    public function openTunnel(): bool
    {
        // Kill any existing socket first (idempotent)
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $killProcess = @proc_open(['ssh', '-S', '/tmp/oempro-tunnel', '-O', 'exit', 'caspmta5'], $descriptor, $pipes);
        if (is_resource($killProcess)) {
            proc_close($killProcess);
        }

        // Open new ControlMaster tunnel using proc_open for safe argument handling
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
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

        // Wait briefly for tunnel to establish
        sleep(1);
        return true;
    }

    /**
     * Close SSH ControlMaster tunnel socket.
     */
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

    /**
     * Get a database connection pointing at the OemPro MySQL via tunnel.
     */
    public function getOemproDB(string $database): \Illuminate\Database\Connection
    {
        $config = config('email-system.oempro_db');

        // Dynamically add the connection
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

        // Purge any cached connection so the new config takes effect
        DB::purge('oempro');

        return DB::connection('oempro');
    }

    protected function detectAndSetCharset(\Illuminate\Database\Connection $oemDb, string $database): void
    {
        try {
            $row = $oemDb->selectOne(
                "SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?",
                [$database]
            );
            $charset = $row->DEFAULT_CHARACTER_SET_NAME ?? 'utf8mb4';
            $this->line("Database charset: {$charset}");

            if (in_array(strtolower($charset), ['latin1', 'latin1_swedish_ci'])) {
                // Keep connection at utf8mb4 so MySQL auto-converts latin1→UTF-8 on read
                $this->warn("latin1 charset detected — MySQL will auto-convert to UTF-8");
            }
        } catch (\Throwable $e) {
            $this->warn("Could not detect charset: " . $e->getMessage());
        }
    }

    protected function processList(
        \Illuminate\Database\Connection $oemDb,
        string $database,
        object $list,
        bool $dryRun
    ): void {
        $listId = (int) $list->ListID;
        $listName = $list->Name;
        $table = "oempro_subscribers_{$listId}";

        $this->info("Processing list [{$listId}] '{$listName}'...");

        // Count by status for logging (SQL GROUP BY — no memory issue on large lists)
        $counts = $oemDb->table($table)
            ->selectRaw('SubscriptionStatus, COUNT(*) as cnt')
            ->groupBy('SubscriptionStatus')
            ->pluck('cnt', 'SubscriptionStatus')
            ->toArray();

        $subscribed = $counts['Subscribed'] ?? 0;
        $skipped = array_sum($counts) - $subscribed;

        $this->line("  Total: " . array_sum($counts) . " | Subscribed: {$subscribed} | Skipped: {$skipped}");

        foreach ($counts as $status => $count) {
            if ($status !== 'Subscribed') {
                $this->line("    Skipping {$count} with status '{$status}'");
            }
        }

        if ($dryRun) {
            return;
        }

        if ($subscribed === 0) {
            $this->line("  No Subscribed subscribers, skipping.");
            return;
        }

        // Find or create the audience group
        $group = $this->findOrCreateGroup($database, $list);

        // Process subscribers in chunks
        $chunk = [];
        $chunkSize = 1000;
        $imported = 0;

        foreach ($oemDb->table($table)->where('SubscriptionStatus', 'Subscribed')->cursor() as $subscriber) {
            $chunk[] = $subscriber;

            if (count($chunk) >= $chunkSize) {
                $imported += $this->importChunk($oemDb, $group->id, $chunk);
                $this->crossReferenceBounces($group->id);
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            $imported += $this->importChunk($oemDb, $group->id, $chunk);
            $this->crossReferenceBounces($group->id);
        }

        $this->info("  Imported {$imported} subscribers to group '{$group->name}'");
    }

    /**
     * Find or create audience group by prefixed name (idempotent).
     */
    public function findOrCreateGroup(string $database, object $list): \JanDev\EmailSystem\Models\EmailAudienceGroup
    {
        $prefix = $this->getDbPrefix($database);
        $name = $prefix . $list->Name;

        return \JanDev\EmailSystem\Models\EmailAudienceGroup::firstOrCreate(['name' => $name]);
    }

    /**
     * Import a chunk of subscribers using upsert (idempotent re-runs).
     */
    protected function importChunk(
        \Illuminate\Database\Connection $oemDb,
        int $groupId,
        array $subscribers
    ): int {
        $emails = array_map(fn ($s) => strtolower(trim($s->EmailAddress)), $subscribers);

        // Fetch custom field values for this chunk with field name JOIN
        // oempro_custom_field_values has RelFieldID → JOIN oempro_custom_fields for FieldName
        $customFieldRows = $oemDb->table('oempro_custom_field_values as cfv')
            ->join('oempro_custom_fields as cf', 'cf.CustomFieldID', '=', 'cfv.RelFieldID')
            ->whereIn('cfv.EmailAddress', $emails)
            ->where('cfv.RelListID', 0) // Global fields
            ->select('cfv.EmailAddress', 'cf.FieldName', 'cfv.ValueText')
            ->get();

        // Build email → [fname, country] map
        $customFieldMap = [];
        foreach ($customFieldRows as $cfRow) {
            $email = strtolower(trim($cfRow->EmailAddress));
            $fieldName = strtolower($cfRow->FieldName ?? '');
            $customFieldMap[$email][$fieldName] = $cfRow->ValueText ?? '';
        }

        $rows = [];
        foreach ($subscribers as $subscriber) {
            $rows[] = $this->buildSubscriberRow($groupId, $subscriber, $customFieldMap);
        }

        // Upsert by (email, email_audience_group_id) — idempotent re-runs
        DB::table('audience_users')->upsert(
            $rows,
            ['email', 'email_audience_group_id'],
            ['name', 'custom_fields', 'updated_at']
        );

        return count($rows);
    }

    /**
     * After bulk insert, run UPDATE JOIN to mark bounced users.
     * Two-step approach: bulk insert bypasses Observer, so we cross-reference explicitly.
     */
    public function crossReferenceBounces(int $groupId): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // MySQL: efficient JOIN UPDATE
            DB::statement("
                UPDATE audience_users au
                JOIN bounced_emails be ON LOWER(be.email) = LOWER(au.email)
                SET au.bounced = 1,
                    au.is_active = 0,
                    au.bounce_type = be.bounce_type,
                    au.bounced_at = COALESCE(be.bounced_at, NOW())
                WHERE au.email_audience_group_id = ?
                  AND au.bounced = 0
            ", [$groupId]);
        } else {
            // SQLite / other: subquery-based UPDATE
            DB::statement("
                UPDATE audience_users
                SET bounced = 1,
                    is_active = 0,
                    bounce_type = (SELECT bounce_type FROM bounced_emails WHERE LOWER(email) = LOWER(audience_users.email) LIMIT 1),
                    bounced_at = COALESCE((SELECT bounced_at FROM bounced_emails WHERE LOWER(email) = LOWER(audience_users.email) LIMIT 1), datetime('now'))
                WHERE email_audience_group_id = ?
                  AND bounced = 0
                  AND EXISTS (SELECT 1 FROM bounced_emails WHERE LOWER(email) = LOWER(audience_users.email))
            ", [$groupId]);
        }
    }

    /**
     * Build a DB row array for a single subscriber.
     */
    public function buildSubscriberRow(int $groupId, object $subscriber, array $customFieldMap): array
    {
        $email = strtolower(trim($subscriber->EmailAddress));
        $fields = $customFieldMap[$email] ?? [];

        $name = $fields['fname'] ?? '';
        $country = $fields['country'] ?? null;

        $customFields = [];
        if ($country) {
            $customFields['country'] = $country;
        }

        $now = now()->toDateTimeString();

        return [
            'email'                   => $email,
            'name'                    => $name,
            'email_audience_group_id' => $groupId,
            'is_active'               => true,
            'bounced'                 => false,
            'bounce_type'             => null,
            'custom_fields'           => !empty($customFields) ? json_encode($customFields) : null,
            'unsubscribe_token'       => Str::random(32),
            'created_at'              => isset($subscriber->SubscriptionDate) ? $subscriber->SubscriptionDate : $now,
            'updated_at'              => $now,
        ];
    }

    /**
     * Get the DB prefix for group names.
     */
    public function getDbPrefix(string $database): string
    {
        return self::DB_PREFIXES[$database] ?? '';
    }

    /**
     * Map OemPro BounceType to our bounce_type value.
     */
    public function mapBounceType(string $oemproType): ?string
    {
        return match ($oemproType) {
            'Hard'  => 'hard',
            'Soft'  => 'soft',
            default => null,
        };
    }

    /**
     * Check if a subscriber row should be imported (Subscribed only).
     */
    public function isSubscribed(object $subscriber): bool
    {
        return ($subscriber->SubscriptionStatus ?? '') === 'Subscribed';
    }
}
