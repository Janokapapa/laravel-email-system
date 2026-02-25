# Laravel Email System

A complete email marketing system for Laravel 11 with Mailgun integration, audience management, and Filament 4 admin panel.

## Features

- Email template management
- Audience group management with subscriber tracking
- Batch email sending via Mailgun API
- Webhook processing for delivery tracking (delivered, opened, clicked, bounced, complained)
- Automatic bounce handling (deactivates bounced emails)
- Unsubscribe link generation and handling
- Email open tracking via tracking pixel
- Filament 4 admin panel integration

## Installation

### 1. Add the package via Composer

For local development, add to your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/var/www/packages/jandev/laravel-email-system"
        }
    ]
}
```

Then require the package:

```bash
composer require jandev/laravel-email-system
```

### 2. Publish configuration

```bash
php artisan vendor:publish --tag=email-system-config
```

### 3. Run migrations

```bash
php artisan migrate
```

### 4. Add to Filament Panel

In your `AdminPanelProvider.php`:

```php
use JanDev\EmailSystem\Filament\EmailSystemPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugins([
            EmailSystemPlugin::make(),
        ]);
}
```

## Configuration

Add these to your `.env`:

```env
MAILGUN_DOMAIN=your-domain.com
MAILGUN_SECRET=your-api-key
MAILGUN_ENDPOINT=https://api.eu.mailgun.net
MAILGUN_WEBHOOK_SIGNING_KEY=your-webhook-key
```

### Custom Callbacks

You can configure callbacks in `config/email-system.php` to integrate with your application:

```php
'blocked_emails_callback' => function () {
    // Return array of additional blocked emails
    return User::where('want_newsletter', false)->pluck('email')->toArray();
},

'bounce_handler' => function (string $email, string $reason) {
    // Handle bounce in your User model
    User::where('email', $email)->update(['email_bounced' => true]);
},

'unsubscribe_handler' => function (string $email) {
    User::where('email', $email)->update(['want_newsletter' => false]);
},
```

## Usage

### Queue emails for an audience

```php
use JanDev\EmailSystem\Jobs\QueueEmailsForAudience;

QueueEmailsForAudience::dispatch(
    templateId: $template->id,
    audienceGroupId: $audienceGroup->id,
    skipYahoo: false,
    userId: auth()->id()
);
```

### Process queued emails (add to scheduler)

```php
// In app/Console/Kernel.php
use JanDev\EmailSystem\Jobs\SendQueuedEmails;

$schedule->job(new SendQueuedEmails)->everyMinute();
```

### Mailgun Webhook

Configure your Mailgun webhook URL to:

```
https://your-domain.com/email-system/webhook/mailgun
```

## Routes

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| GET | /email-system/unsubscribe | email-system.unsubscribe | Unsubscribe page |
| GET | /email-system/track/open/{log_id} | email-system.track.open | Open tracking pixel |
| POST | /email-system/webhook/mailgun | email-system.webhook.mailgun | Mailgun webhook |

## Permission Protection

The Filament resources are protected by default. Users need one of:
- `super-admin` role
- `editor` role
- `admin` role
- `manage content` permission

### Custom Permission Override

To use custom permission logic, extend the resource classes in your app:

```php
// app/Filament/Admin/Resources/EmailTemplateResource.php
namespace App\Filament\Admin\Resources;

use JanDev\EmailSystem\Filament\Resources\EmailTemplateResource as BaseResource;

class EmailTemplateResource extends BaseResource
{
    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super-admin')
            || auth()->user()?->hasPermissionTo('manage emails');
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    // ... other can* methods
}
```

Then register your custom resource in the plugin:

```php
EmailSystemPlugin::make()
    ->resources([
        \App\Filament\Admin\Resources\EmailTemplateResource::class,
    ]),
```

## License

MIT
