<?php

namespace JanDev\EmailSystem\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use JanDev\EmailSystem\Filament\Resources\EmailTemplateResource;
use JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource;
use JanDev\EmailSystem\Filament\Resources\EmailLogResource;
use JanDev\EmailSystem\Filament\Resources\AudienceUserResource;

class EmailSystemPlugin implements Plugin
{
    public function getId(): string
    {
        return 'email-system';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            EmailTemplateResource::class,
            EmailAudienceGroupResource::class,
            EmailLogResource::class,
            AudienceUserResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        return filament(app(static::class)->getId());
    }
}
