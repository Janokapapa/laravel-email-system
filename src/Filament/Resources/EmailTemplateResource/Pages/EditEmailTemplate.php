<?php

namespace JanDev\EmailSystem\Filament\Resources\EmailTemplateResource\Pages;

use JanDev\EmailSystem\Filament\Resources\EmailTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
