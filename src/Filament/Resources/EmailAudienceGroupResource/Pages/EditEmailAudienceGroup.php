<?php

namespace JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource\Pages;

use JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource;
use JanDev\EmailSystem\Filament\Widgets\AudienceGroupStatsWidget;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmailAudienceGroup extends EditRecord
{
    protected static string $resource = EmailAudienceGroupResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            AudienceGroupStatsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
