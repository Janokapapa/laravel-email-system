<?php

namespace JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource\Pages;

use JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmailAudienceGroup extends EditRecord
{
    protected static string $resource = EmailAudienceGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
