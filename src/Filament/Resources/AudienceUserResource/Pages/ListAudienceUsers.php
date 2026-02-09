<?php

namespace JanDev\EmailSystem\Filament\Resources\AudienceUserResource\Pages;

use JanDev\EmailSystem\Filament\Resources\AudienceUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAudienceUsers extends ListRecords
{
    protected static string $resource = AudienceUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
