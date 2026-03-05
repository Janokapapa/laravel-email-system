<?php

namespace JanDev\EmailSystem\Filament\Resources\BouncedEmailResource\Pages;

use JanDev\EmailSystem\Filament\Resources\BouncedEmailResource;
use JanDev\EmailSystem\Filament\Widgets\BounceStatsWidget;
use Filament\Resources\Pages\ListRecords;

class ListBouncedEmails extends ListRecords
{
    protected static string $resource = BouncedEmailResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            BounceStatsWidget::class,
        ];
    }
}
