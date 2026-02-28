<?php

namespace JanDev\EmailSystem\Filament\Resources\EmailTemplateResource\Pages;

use JanDev\EmailSystem\Filament\Resources\EmailTemplateResource;
use JanDev\EmailSystem\Jobs\QueueEmailsForAudience;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\EmailTemplateVariation;
use JanDev\EmailSystem\Support\SenderResolver;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

use function JanDev\EmailSystem\resolve_callback;

class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    // Pending send data for confirmation step
    public ?int $pendingAudienceGroupId = null;
    public ?bool $pendingSkipYahoo = null;
    public ?int $pendingNewCount = null;
    public ?int $pendingAlreadySentCount = null;
    public ?string $pendingSenderName = null;

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
            $this->sendMailAction(),
            $this->confirmSendAction(),
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

    protected function sendMailAction(): Action
    {
        // Get groups that already received this template
        $sentGroupIds = EmailLog::where('email_template_id', $this->record->id)
            ->whereIn('status', ['sent', 'queued', 'spooled'])
            ->distinct()
            ->pluck('email_audience_group_id')
            ->filter()
            ->toArray();

        return Action::make('sendEmail')
            ->label(__('Send Mail to Audience'))
            ->form([
                Select::make('audienceGroupId')
                    ->label(__('Select Audience Group'))
                    ->options(function () use ($sentGroupIds) {
                        return EmailAudienceGroup::orderBy('name')
                            ->get()
                            ->mapWithKeys(function ($group) use ($sentGroupIds) {
                                $label = $group->name;
                                if (in_array($group->id, $sentGroupIds)) {
                                    $count = EmailLog::where('email_template_id', $this->record->id)
                                        ->where('email_audience_group_id', $group->id)
                                        ->whereIn('status', ['sent', 'queued', 'spooled'])
                                        ->count();
                                    $label = "✓ {$group->name} ({$count} " . __('sent') . ")";
                                }
                                return [$group->id => $label];
                            })
                            ->toArray();
                    })
                    ->required()
                    ->searchable(),
                Checkbox::make('skipYahoo')
                    ->label(__('Skip Yahoo/Ymail'))
                    ->helperText(__('Skip recipients with @yahoo or @ymail addresses'))
                    ->default(true),
                Select::make('senderName')
                    ->label(__('Sender'))
                    ->options(fn () => SenderResolver::options())
                    ->default(fn () => SenderResolver::getDefault()['name'] ?? null)
                    ->placeholder(__('Default (from config)'))
                    ->searchable(),
            ])
            ->action(function (array $data) {
                // Auto-save template before sending
                $this->save(false);

                $audienceGroup = EmailAudienceGroup::findOrFail($data['audienceGroupId']);
                $skipYahoo     = $data['skipYahoo'] ?? false;
                $senderName    = $data['senderName'] ?? null;

                // Validate sender still exists
                if ($senderName && !SenderResolver::get($senderName)) {
                    Notification::make()
                        ->title(__('Sender not found'))
                        ->body(__('The selected sender ":name" no longer exists. Please select a different sender.', ['name' => $senderName]))
                        ->danger()
                        ->send();
                    return;
                }

                // Use subqueries to avoid "too many placeholders" with large datasets
                $alreadySentQuery = EmailLog::where('email_template_id', $this->record->id)
                    ->whereIn('status', ['sent', 'queued', 'spooled']);

                $alreadySentCount = $alreadySentQuery->count();

                $query = $audienceGroup->audienceUsers()
                    ->where('is_active', true)
                    ->where('bounced', false)
                    ->whereNotIn('email', (clone $alreadySentQuery)->select('recipient'));

                // Exclude blocked (bounced/inactive in other groups) via subquery
                $blockedQuery = AudienceUser::where(function ($q) {
                        $q->where('is_active', false)->orWhere('bounced', true);
                    })->select('email');

                $query->whereNotIn('email', $blockedQuery);

                // Get additional blocked from config callback
                $blockedCallback = resolve_callback(config('email-system.blocked_emails_callback'));
                if ($blockedCallback) {
                    $additionalBlocked = collect($blockedCallback());
                    if ($additionalBlocked->isNotEmpty()) {
                        // Chunk to avoid placeholder limit
                        foreach ($additionalBlocked->chunk(5000) as $chunk) {
                            $query->whereNotIn('email', $chunk);
                        }
                    }
                }

                if ($skipYahoo) {
                    $query->where('email', 'not regexp', '@(yahoo|ymail)\\.');
                }

                $newCount = $query->count();

                if ($newCount === 0) {
                    Notification::make()
                        ->title(__('No new recipients'))
                        ->body(__('All :count recipients already received this newsletter.', [
                            'count' => number_format($alreadySentCount),
                        ]))
                        ->warning()
                        ->send();
                    return;
                }

                // Store data - the confirm button will appear in the header
                $this->pendingAudienceGroupId   = $audienceGroup->id;
                $this->pendingSkipYahoo         = $skipYahoo;
                $this->pendingNewCount          = $newCount;
                $this->pendingAlreadySentCount  = $alreadySentCount;
                $this->pendingSenderName        = $senderName;

                $skipInfo = $alreadySentCount > 0
                    ? ' (' . number_format($alreadySentCount) . ' ' . __('already sent, skipped') . ')'
                    : '';

                Notification::make()
                    ->title(__('Ready to send'))
                    ->body(number_format($newCount) . ' ' . __('new recipients found') . $skipInfo . '. ' . __('Click the "Confirm & Send" button to proceed.'))
                    ->info()
                    ->persistent()
                    ->send();
            });
    }

    protected function confirmSendAction(): Action
    {
        return Action::make('confirmSend')
            ->label(function () {
                if ($this->pendingNewCount) {
                    return __('Confirm & Send') . ' (' . number_format($this->pendingNewCount) . ')';
                }
                return __('Confirm & Send');
            })
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn () => $this->pendingNewCount !== null)
            ->requiresConfirmation()
            ->modalHeading(__('Confirm sending'))
            ->modalDescription(function () {
                $lines = [];
                $lines[] = __('New recipients: :count', ['count' => number_format($this->pendingNewCount ?? 0)]);
                if ($this->pendingAlreadySentCount > 0) {
                    $lines[] = __('Already sent (skipped): :count', ['count' => number_format($this->pendingAlreadySentCount)]);
                }
                return implode("\n", $lines);
            })
            ->modalSubmitActionLabel(__('Send'))
            ->action(function () {
                if (!$this->pendingAudienceGroupId || !$this->pendingNewCount) {
                    return;
                }

                QueueEmailsForAudience::dispatch(
                    $this->record->id,
                    $this->pendingAudienceGroupId,
                    $this->pendingSkipYahoo ?? false,
                    auth()->id(),
                    $this->pendingSenderName
                );

                Notification::make()
                    ->title(__('Queueing started'))
                    ->body(__('Queueing :count emails in the background. You will be notified when done.', [
                        'count' => number_format($this->pendingNewCount),
                    ]))
                    ->info()
                    ->send();

                // Clear pending data
                $this->pendingAudienceGroupId  = null;
                $this->pendingSkipYahoo        = null;
                $this->pendingNewCount         = null;
                $this->pendingAlreadySentCount = null;
                $this->pendingSenderName       = null;
            });
    }
}
