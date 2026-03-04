<?php

namespace JanDev\EmailSystem\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use JanDev\EmailSystem\Filament\Resources\CampaignResource;
use JanDev\EmailSystem\Filament\Resources\EmailTemplateResource;
use JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource;
use JanDev\EmailSystem\Filament\Resources\EmailLogResource;
use JanDev\EmailSystem\Filament\Resources\BouncedEmailResource;
use JanDev\EmailSystem\Filament\Resources\AudienceUserResource;
use JanDev\EmailSystem\Filament\Pages\PmtaStatisticsPage;
use JanDev\EmailSystem\Filament\Pages\PmtaServerDetailPage;
use JanDev\EmailSystem\Filament\Widgets\JobProgressWidget;
use JanDev\EmailSystem\Filament\Widgets\PmtaStatsWidget;
use JanDev\EmailSystem\Filament\Widgets\PmtaDomainChartWidget;

class EmailSystemPlugin implements Plugin
{
    public function getId(): string
    {
        return 'email-system';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            CampaignResource::class,
            EmailTemplateResource::class,
            EmailAudienceGroupResource::class,
            EmailLogResource::class,
            BouncedEmailResource::class,
            AudienceUserResource::class,
        ]);

        $panel->pages([
            PmtaStatisticsPage::class,
            PmtaServerDetailPage::class,
        ]);

        $panel->widgets([
            PmtaStatsWidget::class,
            PmtaDomainChartWidget::class,
            JobProgressWidget::class,
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
