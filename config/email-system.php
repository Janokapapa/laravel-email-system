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
        'servers' => ['caspmta1', 'caspmta2', 'caspmta3', 'caspmta4'],
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
