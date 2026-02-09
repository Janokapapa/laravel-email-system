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
    | Cleanup Callback
    |--------------------------------------------------------------------------
    |
    | Custom cleanup for mailgun events table if it exists in host app.
    | function(int $days, Carbon $cutoff): int { return $deletedCount; }
    |
    */
    'cleanup_events_callback' => null,
];
