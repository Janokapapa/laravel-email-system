<?php

namespace JanDev\EmailSystem\Filament\Resources\CampaignResource\Pages;

use JanDev\EmailSystem\Filament\Resources\CampaignResource;
use JanDev\EmailSystem\Jobs\DispatchCampaign;
use JanDev\EmailSystem\Jobs\SendQueuedEmail;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\EmailTemplate;
use JanDev\EmailSystem\Support\SenderResolver;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\HtmlString;

class EditCampaign extends EditRecord
{
    use HasWizard;

    protected static string $resource = CampaignResource::class;

    public function getTitle(): string
    {
        return __('Edit Campaign') . ': ' . ($this->record->name ?? '');
    }

    public function getStartStep(): int
    {
        return max(1, (int) ($this->record->current_step ?? 1));
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Redirect non-new campaigns back to list
        if ($this->record->status !== 'new') {
            $this->redirect($this->getResource()::getUrl('index'));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send_campaign')
                ->label(__('Send Campaign'))
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading(__('Send Campaign'))
                ->modalDescription(function (): string {
                    $groupIds = $this->record->audience_group_ids ?? [];
                    $total = 0;
                    foreach ($groupIds as $id) {
                        $group = EmailAudienceGroup::find($id);
                        if ($group) {
                            $total += $group->audienceUsers()
                                ->where('is_active', true)
                                ->where('bounced', false)
                                ->count();
                        }
                    }
                    return __('This will send to approximately :count recipients. Continue?', [
                        'count' => number_format($total),
                    ]);
                })
                ->action(function (): void {
                    $this->save();
                    $this->dispatchCampaign();
                })
                ->visible(fn (): bool => $this->record->status === 'new'),

            DeleteAction::make(),
        ];
    }

    protected function dispatchCampaign(): void
    {
        $groupIds = $this->record->fresh()->audience_group_ids ?? [];

        // Validate all groups still exist
        $missing = collect($groupIds)
            ->filter(fn ($id) => !EmailAudienceGroup::find($id))
            ->count();

        if ($missing > 0) {
            Notification::make()
                ->title(__('Some lists were deleted'))
                ->body(__(':count selected list(s) no longer exist. Remove them from step 2 and try again.', ['count' => $missing]))
                ->danger()
                ->send();
            return;
        }

        // Calculate total recipients
        $total = 0;
        foreach ($groupIds as $id) {
            $group = EmailAudienceGroup::find($id);
            if (!$group) continue;
            $total += $group->audienceUsers()
                ->where('is_active', true)
                ->where('bounced', false)
                ->count();
        }

        $this->record->update([
            'status'           => 'sending',
            'total_recipients' => $total,
            'sent_at'          => now(),
        ]);

        DispatchCampaign::dispatch($this->record);

        Notification::make()
            ->title(__('Campaign dispatched'))
            ->body(__('Sending to :count recipients in the background.', ['count' => number_format($total)]))
            ->success()
            ->send();

        $this->redirect($this->getResource()::getUrl('index'));
    }

    protected function getSteps(): array
    {
        return [
            // ─── Step 1: Sender ──────────────────────────────────────────────
            Step::make(__('Sender'))
                ->icon('heroicon-o-user')
                ->schema([
                    TextInput::make('name')
                        ->label(__('Campaign Name'))
                        ->required(),

                    Select::make('sender_name')
                        ->label(__('Sender'))
                        ->options(fn () => SenderResolver::options())
                        ->required()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state) {
                            if ($state) {
                                $config = SenderResolver::get($state);
                                $set('sender_display_name', $config['from_name'] ?? '');
                                $set('sender_address', $config['from_address'] ?? '');
                            }
                        }),

                    TextInput::make('sender_display_name')
                        ->label(__('From Name'))
                        ->required(),

                    TextInput::make('sender_address')
                        ->label(__('From Address'))
                        ->email()
                        ->required(),
                ]),

            // ─── Step 2: Lists ───────────────────────────────────────────────
            Step::make(__('Lists'))
                ->icon('heroicon-o-users')
                ->schema([
                    CheckboxList::make('audience_group_ids')
                        ->label(__('Email Lists'))
                        ->options(function () {
                            return EmailAudienceGroup::orderBy('name')
                                ->get()
                                ->mapWithKeys(function ($group) {
                                    $active = $group->audienceUsers()
                                        ->where('is_active', true)
                                        ->where('bounced', false)
                                        ->count();
                                    return [$group->id => $group->name . ' (' . number_format($active) . ' ' . __('active') . ')'];
                                });
                        })
                        ->required()
                        ->searchable()
                        ->columns(2),

                    Toggle::make('skip_yahoo')
                        ->label(__('Skip Yahoo/Ymail'))
                        ->helperText(__('Skip recipients with @yahoo or @ymail addresses'))
                        ->default(true),
                ]),

            // ─── Step 3: Template ─────────────────────────────────────────────
            Step::make(__('Template'))
                ->icon('heroicon-o-document-text')
                ->schema([
                    Select::make('email_template_id')
                        ->label(__('Load from Template (optional)'))
                        ->options(fn () => EmailTemplate::orderBy('name')->pluck('name', 'id'))
                        ->nullable()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state) {
                            if ($state) {
                                $template = EmailTemplate::find((int) $state);
                                if ($template) {
                                    $set('subject', $template->subject);
                                    $set('body', $template->body);
                                }
                            }
                        }),

                    TextInput::make('subject')
                        ->label(__('Subject'))
                        ->required(),

                    $this->buildPlaceholderHint(),

                    Field::make('body')
                        ->label(__('Email Body'))
                        ->view('email-system::forms.tinymce')
                        ->extraAttributes(['height' => 500])
                        ->columnSpanFull()
                        ->dehydrated(true)
                        ->dehydrateStateUsing(fn ($state) => $state)
                        ->required(),
                ]),

            // ─── Step 4: Test & Preview ───────────────────────────────────────
            Step::make(__('Test & Preview'))
                ->icon('heroicon-o-eye')
                ->schema([
                    Placeholder::make('test_hint')
                        ->label('')
                        ->content(new HtmlString(
                            '<p class="text-sm text-gray-600 dark:text-gray-400">' .
                            __('Send a test email to verify the campaign before sending.') .
                            ' <a href="' . route('email-system.campaign.preview', $this->record->id) . '" target="_blank" class="text-primary-600 underline">' . __('Web Preview') . '</a>' .
                            '</p>'
                        )),

                    TextInput::make('test_email')
                        ->label(__('Test Email Address'))
                        ->email()
                        ->default(fn () => auth()->user()->email ?? ''),
                ]),

            // ─── Step 5: Confirm & Send ──────────────────────────────────────
            Step::make(__('Send'))
                ->icon('heroicon-o-paper-airplane')
                ->schema([
                    Placeholder::make('send_summary')
                        ->label(__('Campaign Summary'))
                        ->content(function (): HtmlString {
                            $record = $this->record;
                            $groupIds = $record->audience_group_ids ?? [];

                            $listItems = collect($groupIds)->map(function ($id) {
                                $group = EmailAudienceGroup::find($id);
                                if (!$group) return __('Unknown (deleted)');
                                $active = $group->audienceUsers()
                                    ->where('is_active', true)
                                    ->where('bounced', false)
                                    ->count();
                                return $group->name . ' (' . number_format($active) . ' ' . __('active') . ')';
                            })->join(', ');

                            $html = '<div class="space-y-2 text-sm">';
                            $html .= '<div><strong>' . __('Campaign') . ':</strong> ' . e($record->name) . '</div>';
                            $html .= '<div><strong>' . __('Sender') . ':</strong> ' . e($record->sender_name) . ' &lt;' . e($record->sender_address) . '&gt;</div>';
                            $html .= '<div><strong>' . __('Subject') . ':</strong> ' . e($record->subject) . '</div>';
                            $html .= '<div><strong>' . __('Lists') . ':</strong> ' . e($listItems ?: '—') . '</div>';
                            $html .= '</div>';

                            return new HtmlString($html);
                        })
                        ->columnSpanFull(),
                ]),
        ];
    }

    protected function buildPlaceholderHint(): Placeholder
    {
        $tags = ['{{name}}', '{{email}}', '{{unsubscribe=Unsubscribe here}}'];

        if (class_exists(\JanDev\UserManagement\Models\Setting::class)) {
            $definitions = AudienceUser::getCustomFieldDefinitions();
            foreach ($definitions as $field) {
                $slug = $field['slug'] ?? null;
                if ($slug && preg_match('/^[a-zA-Z0-9_]+$/', $slug)) {
                    $tags[] = '{{' . $slug . '}}';
                }
            }
        }

        $tagList = implode(', ', array_map(fn ($t) => '<code>' . e($t) . '</code>', $tags));

        return Placeholder::make('placeholder_hint')
            ->label(__('Available Placeholders'))
            ->content(new HtmlString($tagList))
            ->columnSpanFull();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Track that they've seen all steps
        $data['current_step'] = 5;
        return $data;
    }

    public function getSubmitFormActionLabel(): string
    {
        return __('Save Campaign');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('Campaign saved');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
