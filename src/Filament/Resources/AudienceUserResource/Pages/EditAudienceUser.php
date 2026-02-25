<?php

namespace JanDev\EmailSystem\Filament\Resources\AudienceUserResource\Pages;

use JanDev\EmailSystem\Filament\Resources\AudienceUserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAudienceUser extends EditRecord
{
    protected static string $resource = AudienceUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
