<?php

namespace JanDev\EmailSystem\Filament\Resources\CampaignResource\Pages;

use JanDev\EmailSystem\Filament\Resources\CampaignResource;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\EmailTemplate;
use JanDev\EmailSystem\Support\CampaignFilterBuilder;
use JanDev\EmailSystem\Support\CampaignSummaryBuilder;
use JanDev\EmailSystem\Support\ContentTypeConverter;
use JanDev\EmailSystem\Support\SenderResolver;
use JanDev\EmailSystem\Support\Sms\ShortLinkClient;
use JanDev\EmailSystem\Support\Sms\SmsPricing;
use JanDev\EmailSystem\Support\Sms\SmsText;
use JanDev\EmailSystem\Jobs\SendQueuedEmail;
use JanDev\EmailSystem\Jobs\DispatchCampaign;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Component;
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
    // See EditCampaign: the trait method has to be aliased, parent:: cannot
    // reach it.
    use HasWizard {
        getWizardComponent as protected baseWizardComponent;
    }

    protected static string $resource = CampaignResource::class;

    // Holds the draft campaign ID if we saved mid-wizard
    public ?int $draftCampaignId = null;

    public function mount(): void
    {
        parent::mount();

        // Resume draft from query parameter only
        $draftId = request()->query('draft');
        $draft = $draftId
            ? Campaign::where('id', $draftId)->where('status', 'new')->first()
            : null;

        if ($draft) {
            $this->draftCampaignId = $draft->id;

            $this->form->fill([
                'name'               => $draft->name,
                'sender_name'        => $draft->sender_name,
                'sender_display_name' => $draft->sender_display_name,
                'sender_address'     => $draft->sender_address,
                'audience_group_ids'    => $draft->audience_group_ids ?? [],
                'custom_field_filters' => $draft->custom_field_filters ?? [],
                'skip_providers'       => $draft->skip_providers ?? [],
                'email_template_id'  => $draft->email_template_id,
                'content_type'       => $draft->content_type ?? 'both',
                'subject'            => $draft->subject,
                'body'               => $draft->body,
                'variations'         => $draft->variations ?? [],
            ]);
        } else {
            // Pre-fill sender fields from default sender value
            $senderName = $this->data['sender_name'] ?? null;
            if ($senderName) {
                $config = SenderResolver::get($senderName);
                if ($config) {
                    $this->data['sender_display_name'] = $config['from_name'] ?? '';
                    $this->data['sender_address'] = $config['from_address'] ?? '';
                    $this->data['reply_to'] = $config['reply_to'] ?? $config['from_address'] ?? '';
                }
            }
        }
    }

    public function getStartStep(): int
    {
        // Called before mount(), so read directly from request
        $urlStep = (int) request()->query('step', 0);
        if ($urlStep > 0) {
            return $urlStep;
        }

        $draftId = request()->query('draft');
        if ($draftId) {
            $draft = Campaign::where('id', $draftId)->where('status', 'new')->value('current_step');
            if ($draft) {
                return max(1, (int) $draft);
            }
        }

        return 1;
    }

    public function getTitle(): string
    {
        return __('New Campaign');
    }


    /**
     * Keep the current step in the URL.
     *
     * Without this a refresh, or following a link back into the wizard, drops
     * the operator on step one and every earlier step has to be walked again -
     * which on a campaign means re-confirming an audience and a body just to
     * look at the last step.
     */
    public function getWizardComponent(): Component
    {
        return $this->baseWizardComponent()->persistStepInQueryString();
    }

    protected function getSteps(): array
    {
        return [
            // ─── Step 1: Sender ─────────────────────────────────────────────
            Step::make(__('Sender'))
                ->icon('heroicon-o-user')
                ->schema([
                    // Chosen once. Half the fields below mean different things per
                    // channel, so switching one mid-life would leave a campaign whose
                    // recorded cost and audience belong to the other; duplicate instead.
                    Radio::make('channel')
                        ->label(__('Channel'))
                        ->options([
                            Campaign::CHANNEL_EMAIL => __('E-mail'),
                            Campaign::CHANNEL_SMS => __('SMS'),
                        ])
                        ->default(Campaign::CHANNEL_EMAIL)
                        ->inline()
                        ->live()
                        ->required()
                        ->helperText(__('SMS costs money per message and cannot be recalled once sent.')),

                    TextInput::make('name')
                        ->label(__('Campaign Name'))
                        ->required()
                        ->placeholder(__('e.g. January Newsletter 2026')),

                    Select::make('sender_name')
                        ->visible(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS)
                        ->label(__('Sender'))
                        ->options(fn () => SenderResolver::options())
                        ->default(function () {
                            // Last used sender from most recent campaign
                            $last = Campaign::whereNotNull('sender_name')
                                ->latest('id')
                                ->value('sender_name');
                            if ($last && SenderResolver::get($last)) {
                                return $last;
                            }
                            // Fallback: first available sender
                            $options = SenderResolver::options();
                            return $options ? array_key_first($options) : null;
                        })
                        ->required(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS)
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state) {
                            if ($state) {
                                $config = SenderResolver::get($state);
                                $set('sender_display_name', $config['from_name'] ?? '');
                                $set('sender_address', $config['from_address'] ?? '');
                                $set('reply_to', $config['reply_to'] ?? $config['from_address'] ?? '');
                            }
                        }),

                    TextInput::make('sender_display_name')
                        ->visible(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS)
                        ->label(__('From Name'))
                        ->required(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS),

                    TextInput::make('sender_address')
                        ->visible(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS)
                        ->label(__('From Address'))
                        ->email()
                        ->required(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS),

                    TextInput::make('reply_to')
                        ->visible(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS)
                        ->label(__('Reply-To'))
                        ->email()
                        ->helperText(__('Leave empty to use From Address'))
                        ->nullable(),
                ])
                ->afterValidation(function () {
                    $this->saveStepDraft(1);
                }),

            // ─── Step 2: Lists ───────────────────────────────────────────────
            Step::make(__('Lists'))
                ->icon('heroicon-o-users')
                ->schema([
                    Select::make('audience_group_ids')
                        ->label(__('Lists'))
                        ->options(function () {
                            $counts = \Illuminate\Support\Facades\DB::table('audience_users')
                                ->selectRaw('email_audience_group_id, COUNT(*) as cnt')
                                ->where('is_active', true)
                                ->where('bounced', false)
                                ->groupBy('email_audience_group_id')
                                ->pluck('cnt', 'email_audience_group_id');

                            return EmailAudienceGroup::orderBy('name')
                                ->get()
                                ->mapWithKeys(function ($group) use ($counts) {
                                    $active = $counts->get($group->id, 0);
                                    return [$group->id => $group->name . ' (' . number_format($active) . ' ' . __('active') . ')'];
                                });
                        })
                        ->required()
                        ->multiple()
                        ->searchable()
                        ->live(),

                    Placeholder::make('sender_list_warning')
                        ->label('')
                        ->content(function (Get $get): HtmlString {
                            $warning = static::resolveSenderWarning(
                                $get('sender_name'),
                                (array) ($get('audience_group_ids') ?? [])
                            );
                            return new HtmlString($warning ?? '');
                        })
                        ->columnSpanFull(),

                    CheckboxList::make('skip_providers')
                        ->label(__('Skip Providers'))
                        ->helperText(__('Skip recipients from selected email providers'))
                        ->options([
                            'yahoo'     => __('Yahoo (yahoo, ymail, aol, aim, verizon)'),
                            'microsoft' => __('Microsoft (hotmail, outlook, live, msn)'),
                            'gmail'     => __('Gmail (gmail, googlemail)'),
                            'icloud'    => __('iCloud (icloud, me, mac)'),
                        ])
                        ->default([])
                        ->columns(2),

                    Section::make(__('Audience Filters'))
                        ->description(__('Filter recipients by custom field values. Leave blank to include all.'))
                        ->schema([
                            ...CampaignFilterBuilder::filterSchema(),
                            Placeholder::make('recipient_count')
                                ->label(__('Filtered Recipients'))
                                ->content(fn (Get $get): HtmlString => CampaignFilterBuilder::buildCountHtml(
                                    (array) ($get('audience_group_ids') ?? []),
                                    (array) ($get('custom_field_filters') ?? []),
                                ))
                                ->columnSpanFull(),
                        ])
                        ->columns(3)
                        ->hidden(fn (): bool => empty(CampaignFilterBuilder::filterSchema())),
                ])
                ->afterValidation(function () {
                    $this->saveStepDraft(2);
                }),

            // ─── Step 3: Template ─────────────────────────────────────────────
            Step::make(__('Template'))
                ->icon('heroicon-o-document-text')
                ->schema([
                    // SMS is plain text whose length is money, so it gets its own
                    // field and a live meter rather than a rich-text editor. The two
                    // things that multiply the bill - accents and pasted smart
                    // punctuation - are invisible in the text itself.
                    Textarea::make('body')
                        ->visible(fn (Get $get): bool => $get('channel') === Campaign::CHANNEL_SMS)
                        ->required(fn (Get $get): bool => $get('channel') === Campaign::CHANNEL_SMS)
                        ->label(__('Message'))
                        ->rows(6)
                        ->live(onBlur: true)
                        ->helperText(__('Placeholders: {{name}}. Links are shortened automatically, one per recipient. No unsubscribe link: opt-out is the STOP keyword.')),

                    Placeholder::make('sms_meter')
                        ->visible(fn (Get $get): bool => $get('channel') === Campaign::CHANNEL_SMS)
                        ->label(__('Length and cost'))
                        ->content(function (Get $get): HtmlString {
                            $body = (string) $get('body');
                            if (trim($body) === '') {
                                return new HtmlString('<span class="text-gray-500">' . __('Type a message to see its length and cost.') . '</span>');
                            }

                            // Measured as it will be sent: links shortened, accents
                            // folded if this install folds them.
                            $measured = SmsText::previewShortenedLinks($body, ShortLinkClient::sampleUrl());
                            if (config('email-system.sms.fold_accents', true)) {
                                $measured = SmsText::foldToGsm7($measured);
                            }

                            $segments = SmsText::segments($measured);
                            $encoding = SmsText::encodingOf($measured);
                            $price = SmsPricing::forPhone('+44');
                            $each = $price === null ? '—' : number_format($segments * $price, 4) . ' EUR';

                            $warn = $encoding === 'UCS-2'
                                ? '<br><span class="text-warning-600">' . __('One character outside the GSM alphabet has halved the per-segment budget. Check for accents or pasted quotes.') . '</span>'
                                : '';

                            return new HtmlString(
                                '<strong>' . $segments . '</strong> ' . __('segment(s)')
                                . ' · ' . mb_strlen($measured) . ' ' . __('characters')
                                . ' · ' . $encoding
                                . ' · ~' . $each . ' ' . __('per recipient (UK rate)')
                                . $warn
                            );
                        }),

                    Select::make('email_template_id')
                        ->visible(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS)
                        ->label(__('Load from Template (optional)'))
                        ->options(fn () => EmailTemplate::orderBy('id', 'desc')->pluck('name', 'id'))
                        ->nullable()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state) {
                            if ($state) {
                                $template = EmailTemplate::with('variations')->find((int) $state);
                                if ($template) {
                                    $set('content_type', $template->content_type ?? 'html');
                                    $set('subject', $template->subject);
                                    $set('body', $template->body);
                                    $set('variations', $template->variations->map(fn ($v) => [
                                        'subject' => $v->subject,
                                        'body'    => $v->body,
                                    ])->toArray());
                                }
                            }
                        }),

                    Select::make('content_type')
                        ->visible(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS)
                        ->label(__('Content Type'))
                        ->options([
                            'both' => __('Both (HTML + Text)'),
                            'html' => __('HTML'),
                            'text' => __('Plain Text'),
                        ])
                        ->default('both')
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set, ?string $old, ?string $state) => ContentTypeConverter::handleContentTypeSwitch($get, $set, $old, $state)),

                    Hidden::make('_html_body_cache')->dehydrated(false),
                    Hidden::make('_text_body_cache')->dehydrated(false),

                    // E-mail only. The HTML editor binds to the same `body`
                    // field as the SMS message box above it, so on an SMS
                    // campaign it would quietly replace the message with markup.
                    TextInput::make('subject')
                        ->label(__('Subject'))
                        ->visible(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS)
                        ->required(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS),

                    static::buildPlaceholderHint()
                        ->visible(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS),

                    Field::make('body')
                        ->visible(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS)
                        ->label(__('Email Body'))
                        ->view('email-system::forms.tinymce')
                        ->extraAttributes(fn (Get $get) => [
                            'height' => 500,
                            'contentType' => $get('content_type') ?? 'html',
                        ])
                        ->columnSpanFull()
                        ->dehydrated(true)
                        ->dehydrateStateUsing(fn ($state) => $state)
                        ->required(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS),

                    Section::make(__('Variations'))
                        ->visible(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS)
                        ->description(__('Subject/body variations — the sender randomly picks one per recipient.'))
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            Repeater::make('variations')
                                ->label(false)
                                ->schema([
                                    TextInput::make('subject')
                                        ->label(__('Subject'))
                                        ->required()
                                        ->columnSpanFull(),

                                    Field::make('body')
                                        ->label(__('Body'))
                                        ->view('email-system::forms.tinymce')
                                        ->extraAttributes(fn (Get $get) => [
                                            'height' => 400,
                                            'contentType' => $get('../../content_type') ?? 'html',
                                        ])
                                        ->columnSpanFull()
                                        ->dehydrated(true)
                                        ->dehydrateStateUsing(fn ($state) => $state),

                                    Hidden::make('_html_body_cache')->dehydrated(false),
                                    Hidden::make('_text_body_cache')->dehydrated(false),
                                ])
                                ->columns(1)
                                ->reorderable()
                                ->reorderableWithDragAndDrop()
                                ->collapsible()
                                ->itemLabel(fn (array $state): ?string => ($state['subject'] ?? '') !== '' ? __('Variation') . ': ' . $state['subject'] : __('New Variation'))
                                ->defaultItems(0)
                                ->addActionLabel(__('Add Variation')),
                        ]),
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
                        ->content(fn (Get $get): HtmlString => new HtmlString(
                            '<p class="text-sm text-gray-600 dark:text-gray-400">' .
                            ($get('channel') === Campaign::CHANNEL_SMS
                                ? __('Send the real message to your own number first. Links are shortened exactly as they will be for a recipient.')
                                : __('Send a test email to verify the campaign before sending.')) .
                            '</p>'
                        )),

                    // A test SMS goes to these numbers and to nobody else: the
                    // sender treats them as a replacement for the audience, so a
                    // mis-click cannot text the list.
                    TextInput::make('test_phone')
                        ->label(__('Test Phone Number(s)'))
                        ->visible(fn (Get $get): bool => $get('channel') === Campaign::CHANNEL_SMS)
                        ->helperText(__('International format, e.g. +447700900123. Several numbers may be separated by commas. The test counts against the daily cap.'))
                        ->suffixAction(
                            \Filament\Actions\Action::make('send_test_sms')
                                ->label(__('Send Test SMS'))
                                ->icon('heroicon-o-device-phone-mobile')
                                ->action(fn () => $this->sendTestSms())
                        ),

                    TextInput::make('test_email')
                        ->label(__('Test Email Address'))
                        ->visible(fn (Get $get): bool => $get('channel') !== Campaign::CHANNEL_SMS)
                        ->email()
                        ->default(fn () => auth()->user()->email ?? '')
                        ->suffixAction(
                            \Filament\Actions\Action::make('send_test')
                                ->label(__('Send Test'))
                                ->icon('heroicon-o-paper-airplane')
                                ->action(fn () => $this->sendTestEmail())
                        ),
                ])
                ->afterValidation(function () {
                    $this->saveStepDraft(4);
                }),

            // ─── Step 5: Confirm & Send ──────────────────────────────────────
            Step::make(__('Send'))
                ->icon('heroicon-o-paper-airplane')
                ->schema([
                    Placeholder::make('send_summary')
                        ->label('')
                        ->content(fn (Get $get): HtmlString => \JanDev\EmailSystem\Support\CampaignSummaryBuilder::build($get, ($get('channel') === Campaign::CHANNEL_SMS)))
                        ->columnSpanFull(),

                    Toggle::make('toggle_schedule_later')
                        ->label(__('Schedule for later'))
                        ->helperText(__('Send the campaign automatically at a future date and time'))
                        ->default(false)
                        ->live()
                        ->dehydrated(false),

                    DateTimePicker::make('scheduled_at')
                        ->label(__('Schedule Date & Time'))
                        ->minDate(fn () => now()->addMinutes(5))
                        ->helperText(__('Times are in :tz', ['tz' => config('app.timezone')]))
                        ->hidden(fn (Get $get) => !$get('toggle_schedule_later'))
                        ->required(fn (Get $get) => (bool) $get('toggle_schedule_later')),
                ]),
        ];
    }

    protected static function buildPlaceholderHint(): Placeholder
    {
        return \JanDev\EmailSystem\Support\PlaceholderHint::make();
    }

    /**
     * Save draft campaign state after each wizard step.
     */
    /**
     * Send the draft's message to hand-typed numbers.
     *
     * The draft is saved first: a test that goes out with the previous text
     * reads as confirmation of an edit that was never sent.
     */
    public function sendTestSms(): void
    {
        $numbers = \JanDev\EmailSystem\Support\Sms\SmsPhone::parseList((string) ($this->data['test_phone'] ?? ''));
        if ($numbers === []) {
            Notification::make()
                ->title(__('No usable number'))
                ->body(__('Use international format, starting with + or 00.'))
                ->danger()
                ->send();
            return;
        }

        $this->data['body'] = \JanDev\EmailSystem\Support\Sms\SmsText::stripHtml((string) ($this->data['body'] ?? ''));
        $this->saveStepDraft(4);

        $campaign = $this->draftCampaignId ? Campaign::find($this->draftCampaignId) : null;
        if (!$campaign) {
            Notification::make()
                ->title(__('Nothing to test yet'))
                ->body(__('Complete the earlier steps first.'))
                ->danger()
                ->send();
            return;
        }

        $result = \JanDev\EmailSystem\Support\Sms\SmsCampaignSender::send($campaign, $numbers);

        if (($result['sent'] ?? 0) === 0) {
            $blocked = \JanDev\EmailSystem\Support\Sms\SmsCampaignSender::blockedReason($campaign);

            Notification::make()
                ->title(__('Test SMS not sent'))
                ->body($blocked ?? __('The provider rejected every number. See the email log for the reason.'))
                ->danger()
                ->send();
            return;
        }

        Notification::make()
            ->title(__('Test SMS sent'))
            ->body(__(':sent sent, :failed failed.', ['sent' => $result['sent'], 'failed' => $result['failed'] ?? 0]))
            ->success()
            ->send();
    }

    protected function saveStepDraft(int $step): void
    {
        try {
            // Access Livewire data directly to avoid any form validation
            $data = $this->data;

            $draft = [
                'name'               => $data['name'] ?? 'Draft Campaign',
                'status'             => 'new',
                'sender_name'        => $data['sender_name'] ?? null,
                'sender_address'     => $data['sender_address'] ?? null,
                'sender_display_name' => $data['sender_display_name'] ?? null,
                'reply_to'           => $data['reply_to'] ?? null,
                'email_template_id'  => $data['email_template_id'] ?? null,
                'content_type'       => $data['content_type'] ?? 'both',
                'subject'            => $data['subject'] ?? null,
                'body'               => $data['body'] ?? null,
                'variations'         => $data['variations'] ?? [],
                'audience_group_ids'    => $data['audience_group_ids'] ?? [],
                'custom_field_filters' => $data['custom_field_filters'] ?? [],
                'skip_providers'       => $data['skip_providers'] ?? [],
                'current_step'       => $step,
            ];

            if ($this->draftCampaignId) {
                Campaign::where('id', $this->draftCampaignId)->update($draft);
            } else {
                $campaign = Campaign::create($draft);
                $this->draftCampaignId = $campaign->id;
            }

            // Update URL with draft ID and next step (user is moving to step+1 after validation)
            $draftId = $this->draftCampaignId;
            $nextStep = $step + 1;
            $this->js("
                const url = new URL(window.location.href);
                url.searchParams.set('draft', '{$draftId}');
                url.searchParams.set('step', '{$nextStep}');
                history.replaceState(null, document.title, url.toString());
            ");
        } catch (\Throwable $e) {
            // Non-fatal — wizard continues even if draft save fails
            \Illuminate\Support\Facades\Log::warning('Campaign draft save failed: ' . $e->getMessage());
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['current_step'] = 5;

        $toggleOn = $this->data['toggle_schedule_later'] ?? false;
        if ($toggleOn && !empty($data['scheduled_at'])) {
            $data['status'] = 'scheduled';
        } else {
            $data['status'] = 'new';
            $data['scheduled_at'] = null;
        }

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
        if ($this->record->status === 'scheduled') {
            return __('Campaign scheduled for :time', [
                'time' => $this->record->scheduled_at->format('M j, Y H:i'),
            ]);
        }

        return __('Campaign saved');
    }

    protected function getRedirectUrl(): string
    {
        if ($this->record->status === 'scheduled') {
            return $this->getResource()::getUrl('index');
        }

        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
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

        // Resolve placeholders with test data
        $subject = $state['subject'] ?? 'Campaign Preview';
        $body = $state['body'] ?? '';
        $placeholders = [
            '{{name}}'  => 'Test Name',
            '{{email}}' => $testEmail,
        ];
        foreach (AudienceUser::getCustomFieldDefinitions() as $field) {
            $slug = $field['slug'] ?? null;
            if ($slug && preg_match('/^[a-zA-Z0-9_]+$/', $slug)) {
                $placeholders['{{' . $slug . '}}'] = 'Test ' . ($field['name'] ?? $slug);
            }
        }
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
        $body = str_replace(array_keys($placeholders), array_values($placeholders), $body);

        $emailLog = EmailLog::create([
            'campaign_id'          => $this->draftCampaignId,
            'recipient'            => $testEmail,
            'subject'              => '[TEST] ' . $subject,
            'message'              => $body,
            'sender'               => $senderAddress,
            'sender_name'          => $senderName,
            'sender_display_name'  => $state['sender_display_name'] ?? ($senderConfig['from_name'] ?? null),
            'reply_to'             => $state['reply_to'] ?? ($senderConfig['reply_to'] ?? null),
            'content_type'         => $state['content_type'] ?? 'html',
            'status'               => 'queued',
        ]);

        try {
            SendQueuedEmail::dispatchSync($emailLog);

            $emailLog->refresh();
            $status = $emailLog->status;

            if (in_array($status, ['sent', 'spooled'])) {
                Notification::make()
                    ->title(__('Test email sent'))
                    ->body(__('Successfully sent to :email', ['email' => $testEmail]))
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title(__('Test email status: :status', ['status' => $status]))
                    ->body($emailLog->error ?: __('Sent to :email', ['email' => $testEmail]))
                    ->warning()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Test email failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Invoke the sender/list mismatch warning hook from config.
     * Configured via email-system.filament.campaign_sender_warnings (invokable class).
     * Returns HTML warning string or null.
     */
    public static function resolveSenderWarning(?string $senderName, array $audienceGroupIds): ?string
    {
        return CampaignSummaryBuilder::resolveSenderWarning($senderName, $audienceGroupIds);
    }
}
