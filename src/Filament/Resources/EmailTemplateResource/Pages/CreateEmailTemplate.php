<?php

namespace JanDev\EmailSystem\Filament\Resources\EmailTemplateResource\Pages;

use JanDev\EmailSystem\Filament\Resources\EmailTemplateResource;
use JanDev\EmailSystem\Models\EmailTemplateVariation;
use Filament\Resources\Pages\CreateRecord;

class CreateEmailTemplate extends CreateRecord
{
    protected static string $resource = EmailTemplateResource::class;

    protected function afterCreate(): void
    {
        EmailTemplateVariation::syncForTemplate($this->record, $this->data['variations'] ?? []);
    }
}
