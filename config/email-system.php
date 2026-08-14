<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mail Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "smtp", "mailgun"
    |
    */
    'driver' => env('EMAIL_SYSTEM_DRIVER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Mailgun Configuration (only if driver = mailgun)
    |--------------------------------------------------------------------------
    */
    'mailgun' => [
        'secret' => env('MAILGUN_SECRET'),
        'domain' => env('MAILGUN_DOMAIN'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'https://api.eu.mailgun.net'),
        'webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMTP Configuration (only if driver = smtp)
    |--------------------------------------------------------------------------
    |
    | Uses Laravel's default mail configuration, or specify a custom mailer.
    |
    */
    'smtp' => [
        'mailer' => env('EMAIL_SYSTEM_MAILER', 'smtp'),
    ],

    /*
    |--------------------------------------------------------------------------
    | PMTA Configuration
    |--------------------------------------------------------------------------
    |
    | bounce_api_key: Must match the API_KEY constant in process-bounces.py.
    | Set PMTA_BOUNCE_API_KEY in .env.
    |
    */
    'pmta' => [
        'bounce_api_key' => env('PMTA_BOUNCE_API_KEY'),
        'servers' => ['caspmta1', 'caspmta2', 'caspmta3', 'caspmta4', 'caspmta5'],
        'stats_retention_days' => (int) env('PMTA_STATS_RETENTION_DAYS', 365),
        // Spool base dir for outgoing/sent/failed EML files (null = storage/app/mailspool)
        'spool_path' => env('EMAIL_SYSTEM_SPOOL_PATH'),

        // Per-server, per-provider virtual-MTA override.
        // Steers recipients of a given inbox provider (as classified by
        // ProviderResolver: microsoft|yahoo|gmail|icloud|default) onto a specific
        // vMTA (e.g. a clean IP pool) for the given PMTA server, overriding the
        // server's default vMTA. Config/DB-driven — enable per server+provider
        // without code changes. Providers not listed keep the server default.
        // Precedence: sender pmta_virtual_mta (explicit) > this map > server default > 'all'.
        // Shape: ['caspmta4' => ['gmail' => 'icloudpool', 'yahoo' => 'icloudpool', 'icloud' => 'icloudpool']]
        'provider_virtual_mta' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram Notifier
    |--------------------------------------------------------------------------
    |
    | Used by scheduled commands (e.g. PmtaBounceSummary) to push aggregated
    | notifications to a Telegram chat. admin_url is embedded in messages as
    | a link to the Filament admin panel.
    |
    */
    'telegram' => [
        'enabled' => env('EMAIL_SYSTEM_TELEGRAM_ENABLED', false),
        'bot_token' => env('EMAIL_SYSTEM_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('EMAIL_SYSTEM_TELEGRAM_CHAT_ID'),
        'admin_url' => env('EMAIL_SYSTEM_ADMIN_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | From Address Configuration
    |--------------------------------------------------------------------------
    */
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

    'reply_to' => env('MAIL_REPLY_TO_ADDRESS'),

    /*
    |--------------------------------------------------------------------------
    | Force Unsubscribe Link
    |--------------------------------------------------------------------------
    |
    | If true, automatically appends an unsubscribe placeholder to the end of
    | any email content that does not already contain {{unsubscribe=...}} or
    | {{unsubscribe_url}}. Default false (preserves existing behaviour for
    | projects that intentionally omit the link, e.g. transactional emails).
    |
    */
    'force_unsubscribe_link' => env('EMAIL_SYSTEM_FORCE_UNSUBSCRIBE_LINK', false),

    /*
    |--------------------------------------------------------------------------
    | SMS Opt-outs Menu
    |--------------------------------------------------------------------------
    |
    | Whether the "SMS Opt-outs" navigation item is shown in the admin panel.
    | Defaults to true. Projects that do not send SMS can hide it with
    | SMS_OPT_OUTS_MENU=no in their .env - the routes stay registered, so an
    | existing deep link keeps working.
    |
    */
    'sms_opt_outs_menu' => env('SMS_OPT_OUTS_MENU', true),

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    */
    'logo_url' => env('EMAIL_SYSTEM_LOGO_URL'),
    'website_url' => env('EMAIL_SYSTEM_WEBSITE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Send Settings
    |--------------------------------------------------------------------------
    */
    'send' => [
        'max_per_run' => 100,
        'delay_seconds' => 1,
        'mailgun_batch_size' => 500,
        'mailgun_batch_delay_ms' => 2000,
        'queue' => 'default',
    ],

    /*
    |--------------------------------------------------------------------------
    | Warmup — per-sending-domain daily volume cap
    |--------------------------------------------------------------------------
    |
    | Protects a fresh sending (From/DKIM) domain from being over-sent on day
    | one. The daily cap ramps up each calendar day since the domain's first
    | send: daily_cap = base * (factor ** warmup_day_index). Once the cap
    | reaches max_daily the domain is warmed up and the overall cap is lifted.
    |
    | A separate, always-on iCloud sub-cap (iCloud is volume-sensitive) limits
    | iCloud recipients to min(icloud_daily_cap, daily_cap) per domain per day.
    | Enforced in the PMTA send path (email:pmta-sync); deferred emails stay
    | 'spooled' for the next run/day. Per-domain overrides live on the
    | email_sending_domains table (warmup_enabled, max_daily).
    |
    */
    'warmup' => [
        'enabled' => env('EMAIL_SYSTEM_WARMUP_ENABLED', true),
        'base' => 50,
        'factor' => 2,
        'max_daily' => 1_000_000,
        'icloud_daily_cap' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes Configuration
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'prefix' => 'email-system',
        'middleware' => ['web'],
        'webhook_middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament Integration
    |--------------------------------------------------------------------------
    */
    'filament' => [
        'navigation_group' => 'Marketing',
        'navigation_icon' => 'heroicon-o-envelope',

        // Invokable class that returns an array of extra Filament table columns
        // for the EmailAudienceGroupResource list. Signature: __invoke(): array
        // Example: \App\Filament\Hooks\CasinoAudienceColumns::class
        'audience_group_extra_columns' => null,

        // Invokable class for sender/list mismatch warnings in the campaign wizard.
        // Signature: __invoke(?string $senderName, array $audienceGroupIds): ?string
        // Return HTML string (for Placeholder/Summary) or null (no warning).
        'campaign_sender_warnings' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Callbacks (for integration with host application)
    |--------------------------------------------------------------------------
    |
    | These callbacks allow you to integrate with your application's
    | user system for things like notifications and additional blocked emails.
    |
    */

    // Return array of additional blocked emails (e.g., users who unsubscribed)
    'blocked_emails_callback' => null,

    // Called when email queueing completes
    // function(int $userId, array $stats) { ... }
    'queue_completion_callback' => null,

    // Called when email queueing fails
    // function(int $userId, string $errorMessage) { ... }
    'queue_failure_callback' => null,

    // Called when all queued emails have been sent
    // function(array $stats) { ... }
    'send_completion_callback' => null,

    // Custom unsubscribe URL generator
    // function(EmailLog $emailLog): ?string { ... }
    'unsubscribe_url_generator' => null,

    // Called when a bounce is received
    // function(string $email, string $reason) { ... }
    'bounce_handler' => null,

    // Called when a complaint is received
    // function(string $email) { ... }
    'complaint_handler' => null,

    // Called when someone unsubscribes
    // function(string $email) { ... }
    'unsubscribe_handler' => null,

    /*
    |--------------------------------------------------------------------------
    | Audience REST API
    |--------------------------------------------------------------------------
    |
    | api.key: API key for the audience groups/subscribers REST API.
    | Set EMAIL_SYSTEM_API_KEY in .env.
    |
    */
    'api' => [
        'key' => env('EMAIL_SYSTEM_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OemPro Migration Database Connection
    |--------------------------------------------------------------------------
    |
    | Used by email:migrate-oempro and email:import-oempro-suppressions commands.
    | The SSH tunnel must be established before running these commands.
    | Tunnel: ssh -S /tmp/oempro-tunnel -M -fNL 33061:localhost:3306 caspmta5
    |
    */
    'oempro_db' => [
        'host'     => '127.0.0.1',
        'port'     => 33061,
        'username' => 'root',
        'password' => env('OEMPRO_DB_PASSWORD', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Email (for watchdog alerts)
    |--------------------------------------------------------------------------
    */
    'admin_email' => env('EMAIL_SYSTEM_ADMIN_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Audience Callbacks
    |--------------------------------------------------------------------------
    |
    | Callbacks for adding users from the host application's user table.
    |
    */

    // Return Collection of users with name, email fields
    // function(): Collection { return User::where('want_newsletter', 1)->get(); }
    'add_subscribed_users_callback' => null,

    // Return Collection of users by date range
    // function(string $dateFrom, string $dateTo): Collection { ... }
    'add_users_by_date_callback' => null,

    /*
    |--------------------------------------------------------------------------
    | Merge Callbacks
    |--------------------------------------------------------------------------
    */

    // Called when audience merge completes
    // function(int $userId, array $stats) { ... }
    'merge_completion_callback' => null,

    // Called when audience merge fails
    // function(int $userId, string $error) { ... }
    'merge_failure_callback' => null,

    /*
    |--------------------------------------------------------------------------
    | ZeroBounce Email Verification
    |--------------------------------------------------------------------------
    |
    | ZeroBounce is an email verification service. Configure your API key and
    | enable verification. Each API call costs 1 credit.
    |
    */
    'zerobounce' => [
        'api_key' => env('ZEROBOUNCE_API_KEY'),
        'enabled' => env('ZEROBOUNCE_ENABLED', false),
        'delay_ms' => env('ZEROBOUNCE_DELAY_MS', 200),
    ],

    // Called when ZeroBounce verification job completes
    // function(int $userId, array $stats) { ... }
    'zerobounce_completion_callback' => null,

    // Called when ZeroBounce verification job fails
    // function(int $userId, string $error) { ... }
    'zerobounce_failure_callback' => null,

    /*
    |--------------------------------------------------------------------------
    | Cleanup Callback
    |--------------------------------------------------------------------------
    |
    | Custom cleanup for mailgun events table if it exists in host app.
    | function(int $days, Carbon $cutoff): int { return $deletedCount; }
    |
    */
    'cleanup_events_callback' => null,
];
