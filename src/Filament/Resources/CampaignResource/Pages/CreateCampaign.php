<?php

namespace JanDev\EmailSystem\Filament\Resources\CampaignResource\Pages;

use JanDev\EmailSystem\Filament\Resources\CampaignResource;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\EmailTemplate;
use JanDev\EmailSystem\Support\SenderResolver;
use JanDev\EmailSystem\Jobs\SendQueuedEmail;
use JanDev\EmailSystem\Jobs\DispatchCampaign;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\HtmlString;

use function JanDev\EmailSystem\resolve_callback;

class CreateCampaign extends CreateRecord
{
    use HasWizard;

    protected static string $resource = CampaignResource::class;

    // Holds the draft campaign ID if we saved mid-wizard
    public ?int $draftCampaignId = null;

    public function getTitle(): string
    {
        return __('New Campaign');
    }

    protected function getSteps(): array
    {
        return [
            // ─── Step 1: Sender ─────────────────────────────────────────────
            Step::make(__('Sender'))
                ->icon('heroicon-o-user')
                ->schema([
                    TextInput::make('name')
                        ->label(__('Campaign Name'))
                        ->required()
                        ->placeholder(__('e.g. January Newsletter 2026')),

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
                ])
                ->afterValidation(function () {
                    $this->saveStepDraft(1);
                }),

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
                ])
                ->afterValidation(function () {
                    $this->saveStepDraft(2);
                }),

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

                    static::buildPlaceholderHint(),

                    Field::make('body')
                        ->label(__('Email Body'))
                        ->view('email-system::forms.tinymce')
                        ->extraAttributes(['height' => 500])
                        ->columnSpanFull()
                        ->dehydrated(true)
                        ->dehydrateStateUsing(fn ($state) => $state)
                        ->required(),
                ])
                ->afterValidation(function () {
                    $this->saveStepDraft(3);
                }),

            // ─── Step 4: Test & Preview ───────────────────────────────────────
            Step::make(__('Test & Preview'))
                ->icon('heroicon-o-eye')
                ->schema([
                    Placeholder::make('test_hint')
                        ->label('')
                        ->content(new HtmlString(
                            '<p class="text-sm text-gray-600 dark:text-gray-400">' .
                            __('Send a test email to verify the campaign before sending.') .
                            '</p>'
                        )),

                    TextInput::make('test_email')
                        ->label(__('Test Email Address'))
                        ->email()
                        ->default(fn () => auth()->user()->email ?? ''),
                ])
                ->afterValidation(function () {
                    $this->saveStepDraft(4);
                }),

            // ─── Step 5: Confirm & Send ──────────────────────────────────────
            Step::make(__('Send'))
                ->icon('heroicon-o-paper-airplane')
                ->schema([
                    Placeholder::make('send_summary')
                        ->label(__('Campaign Summary'))
                        ->content(function (Get $get): HtmlString {
                            $name = $get('name') ?: '—';
                            $senderName = $get('sender_name') ?: '—';
                            $senderAddress = $get('sender_address') ?: '—';
                            $subject = $get('subject') ?: '—';
                            $groupIds = $get('audience_group_ids') ?? [];
                            $skipYahoo = $get('skip_yahoo') ? __('Yes') : __('No');

                            $listNames = collect($groupIds)->map(function ($id) {
                                $group = EmailAudienceGroup::find($id);
                                if (!$group) return __('Unknown (deleted)');
                                $active = $group->audienceUsers()
                                    ->where('is_active', true)
                                    ->where('bounced', false)
                                    ->count();
                                return $group->name . ' (' . number_format($active) . ')';
                            })->join(', ');

                            $html = '<div class="space-y-2 text-sm">';
                            $html .= '<div><strong>' . __('Campaign') . ':</strong> ' . e($name) . '</div>';
                            $html .= '<div><strong>' . __('Sender') . ':</strong> ' . e($senderName) . ' &lt;' . e($senderAddress) . '&gt;</div>';
                            $html .= '<div><strong>' . __('Subject') . ':</strong> ' . e($subject) . '</div>';
                            $html .= '<div><strong>' . __('Lists') . ':</strong> ' . e($listNames ?: '—') . '</div>';
                            $html .= '<div><strong>' . __('Skip Yahoo') . ':</strong> ' . $skipYahoo . '</div>';
                            $html .= '</div>';

                            return new HtmlString($html);
                        })
                        ->columnSpanFull(),
                ]),
        ];
    }

    protected static function buildPlaceholderHint(): Placeholder
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

    /**
     * Save draft campaign state after each wizard step.
     */
    protected function saveStepDraft(int $step): void
    {
        try {
            $state = $this->form->getState();
            $data  = $this->mutateFormDataBeforeCreate($state);

            $draft = [
                'name'               => $data['name'] ?? 'Draft Campaign',
                'status'             => 'new',
                'sender_name'        => $data['sender_name'] ?? null,
                'sender_address'     => $data['sender_address'] ?? null,
                'sender_display_name' => $data['sender_display_name'] ?? null,
                'email_template_id'  => $data['email_template_id'] ?? null,
                'subject'            => $data['subject'] ?? null,
                'body'               => $data['body'] ?? null,
                'audience_group_ids' => $data['audience_group_ids'] ?? [],
                'skip_yahoo'         => (bool) ($data['skip_yahoo'] ?? true),
                'current_step'       => $step,
            ];

            if ($this->draftCampaignId) {
                Campaign::where('id', $this->draftCampaignId)->update($draft);
            } else {
                $campaign = Campaign::create($draft);
                $this->draftCampaignId = $campaign->id;
            }
        } catch (\Throwable $e) {
            // Non-fatal — wizard continues even if draft save fails
            \Illuminate\Support\Facades\Log::warning('Campaign draft save failed: ' . $e->getMessage());
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status']       = 'new';
        $data['current_step'] = 5;
        return $data;
    }

    protected function afterCreate(): void
    {
        // If there was a draft, delete it (we now have the real record)
        if ($this->draftCampaignId && $this->draftCampaignId !== $this->record->id) {
            Campaign::where('id', $this->draftCampaignId)->delete();
        }
    }

    public function getSubmitFormActionLabel(): string
    {
        return __('Save Campaign');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Campaign saved');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    // ─── Send Test Email action (called via header action on edit, or separately) ─
    public function sendTestEmail(): void
    {
        $state = $this->form->getState();

        $testEmail = $state['test_email'] ?? null;
        if (!$testEmail) {
            Notification::make()
                ->title(__('Test email address required'))
                ->danger()
                ->send();
            return;
        }

        $senderName    = $state['sender_name'] ?? null;
        $senderConfig  = $senderName ? SenderResolver::get($senderName) : null;
        $senderAddress = $state['sender_address'] ?? ($senderConfig['from_address'] ?? config('email-system.from.address'));

        $emailLog = EmailLog::create([
            'campaign_id' => $this->draftCampaignId,
            'recipient'   => $testEmail,
            'subject'     => '[TEST] ' . ($state['subject'] ?? 'Campaign Preview'),
            'message'     => $state['body'] ?? '',
            'sender'      => $senderAddress,
            'sender_name' => $senderName,
            'status'      => 'queued',
        ]);

        SendQueuedEmail::dispatch($emailLog);

        Notification::make()
            ->title(__('Test email queued'))
            ->body(__('Sending test to :email', ['email' => $testEmail]))
            ->success()
            ->send();
    }
}
