<?php

namespace JanDev\EmailSystem\Filament\Resources\EmailTemplateResource\Pages;

use JanDev\EmailSystem\Filament\Resources\EmailTemplateResource;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\EmailTemplateVariation;
use JanDev\EmailSystem\Support\SenderResolver;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['variations'] = $this->record
            ->variations()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($v) => [
                'subject' => $v->subject,
                'body'    => $v->body,
            ])
            ->toArray();

        return $data;
    }

    protected function afterSave(): void
    {
        EmailTemplateVariation::syncForTemplate($this->record, $this->data['variations'] ?? []);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            $this->sendTestEmailAction(),
        ];
    }

    protected function sendTestEmailAction(): Action
    {
        return Action::make('sendTestEmail')
            ->label(__('Send Test Email'))
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->form(function () {
                $variationOptions = ['original' => __('Original (default)'), 'random' => __('Random')];
                foreach ($this->record->variations()->orderBy('sort_order')->get() as $index => $v) {
                    $variationOptions[(string) $v->id] = __('Variation') . ' ' . ($index + 1) . ': ' . $v->subject;
                }

                return [
                    TextInput::make('test_email')
                        ->label(__('Email Address'))
                        ->email()
                        ->required()
                        ->default(auth()->user()->email),
                    Select::make('senderName')
                        ->label(__('Sender'))
                        ->options(fn () => SenderResolver::options())
                        ->default(fn () => SenderResolver::getDefault()['name'] ?? null)
                        ->placeholder(__('Default (from config)'))
                        ->searchable(),
                    Select::make('variation')
                        ->label(__('Variation'))
                        ->options($variationOptions)
                        ->default('original')
                        ->visible(count($variationOptions) > 2),
                ];
            })
            ->action(function (array $data) {
                // Auto-save template before sending test
                $this->save(false);

                $senderName   = $data['senderName'] ?? null;
                $senderConfig = $senderName ? SenderResolver::get($senderName) : null;
                $senderAddress = $senderConfig['from_address'] ?? config('email-system.from.address');

                // Resolve content based on chosen variation
                $variationChoice = $data['variation'] ?? 'original';
                [$selectedSubject, $selectedBody, $selectedVariationId] = $this->resolveVariationContent($variationChoice);

                $emailLog = EmailLog::create([
                    'email_template_id' => $this->record->id,
                    'recipient'         => $data['test_email'],
                    'subject'           => '[TEST] ' . $selectedSubject,
                    'message'           => $selectedBody,
                    'sender'            => $senderAddress,
                    'sender_name'       => $senderName,
                    'variation_id'      => $selectedVariationId,
                    'status'            => 'queued',
                ]);

                \JanDev\EmailSystem\Jobs\SendQueuedEmail::dispatch($emailLog);

                Notification::make()
                    ->title(__('Test email queued'))
                    ->body(__('Test email will be sent to :email', ['email' => $data['test_email']]))
                    ->success()
                    ->send();
            });
    }

    private function resolveVariationContent(string $choice): array
    {
        $variations = $this->record->variations()->orderBy('sort_order')->get();

        if ($choice === 'original' || $variations->isEmpty()) {
            return [$this->record->subject, $this->record->body, null];
        }

        if ($choice === 'random') {
            $pool = [['subject' => $this->record->subject, 'body' => $this->record->body, 'id' => null]];
            foreach ($variations as $v) {
                $pool[] = ['subject' => $v->subject, 'body' => $v->body, 'id' => $v->id];
            }
            $picked = $pool[array_rand($pool)];
            return [$picked['subject'], $picked['body'], $picked['id']];
        }

        // Specific variation ID
        $variation = $variations->firstWhere('id', (int) $choice);
        if ($variation) {
            return [$variation->subject, $variation->body, $variation->id];
        }

        return [$this->record->subject, $this->record->body, null];
    }
}
