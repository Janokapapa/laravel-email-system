<?php

namespace JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource\Pages;

use JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmailAudienceGroups extends ListRecords
{
    protected static string $resource = EmailAudienceGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
