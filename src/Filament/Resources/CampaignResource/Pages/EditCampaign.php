<?php

namespace JanDev\EmailSystem\Filament\Resources\CampaignResource\Pages;

use JanDev\EmailSystem\Filament\Resources\CampaignResource;
use JanDev\EmailSystem\Jobs\DispatchCampaign;
use JanDev\EmailSystem\Jobs\DispatchSmsCampaign;
use JanDev\EmailSystem\Jobs\SendQueuedEmail;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\EmailTemplate;
use JanDev\EmailSystem\Services\ZeroBounce;
use JanDev\EmailSystem\Support\CampaignFilterBuilder;
use JanDev\EmailSystem\Support\ContentTypeConverter;
use JanDev\EmailSystem\Support\SenderResolver;
use JanDev\EmailSystem\Support\Sms\ShortLinkClient;
use JanDev\EmailSystem\Support\Sms\SmsPricing;
use JanDev\EmailSystem\Support\Sms\SmsText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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

        // Redirect campaigns that can't be edited to the view page
        if (!in_array($this->record->status, ['new', 'scheduled'])) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send_campaign')
                ->label(fn (): string => ($this->data['toggle_schedule_later'] ?? false)
                    ? __('Schedule Campaign')
                    : __('Send Campaign'))
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation(fn (): bool => !($this->data['toggle_schedule_later'] ?? false))
                ->modalHeading(__('Send Campaign'))
                ->modalDescription(function (): string {
                    $groupIds = $this->record->audience_group_ids ?? [];
                    $filters = $this->record->custom_field_filters ?? [];
                    $groups = EmailAudienceGroup::whereIn('id', $groupIds)->get();
                    $total = 0;
                    $unverified = 0;
                    $invalid = 0;
                    foreach ($groups as $group) {
                        $base = $group->audienceUsers()
                            ->where('is_active', true)
                            ->where('bounced', false);
                        CampaignFilterBuilder::applyFilters($base, $filters);
                        $total += (clone $base)->count();
                        $unverified += (clone $base)->where(function ($q) {
                            $q->whereNull('zerobounce_status')->orWhere('zerobounce_status', 'unverified');
                        })->count();
                        $invalid += (clone $base)->where('zerobounce_status', 'invalid')->count();
                    }

                    $msg = __('This will send to approximately :count recipients.', [
                        'count' => number_format($total - $invalid),
                    ]);

                    if ($invalid > 0) {
                        $msg .= "\n" . __(':count invalid (ZeroBounce) emails will be skipped.', [
                            'count' => number_format($invalid),
                        ]);
                    }
                    if ($unverified > 0 && ZeroBounce::isEnabled()) {
                        $msg .= "\n" . __(':count unchecked emails will be verified by ZeroBounce before sending.', [
                            'count' => number_format($unverified),
                        ]);

                        // Show credit balance (cached 5 seconds to avoid API spam)
                        $sentinel = new \stdClass();
                        $cached   = Cache::get('zerobounce_credits', $sentinel);
                        if ($cached === $sentinel) {
                            $cached = ZeroBounce::getCredits();
                            Cache::put('zerobounce_credits', $cached, 5);
                        }
                        $creditsDisplay = $cached !== null ? number_format((int) $cached) : 'N/A';
                        $msg .= "\n" . __('ZeroBounce credits available: :credits', ['credits' => $creditsDisplay]);
                    } elseif ($unverified > 0) {
                        $msg .= "\n⚠️ " . __(':count emails are not yet verified by ZeroBounce.', [
                            'count' => number_format($unverified),
                        ]);
                    }

                    // Sender/list mismatch warning (plain text for modal dialog)
                    $warningClass = config('email-system.filament.campaign_sender_warnings');
                    if ($warningClass && class_exists($warningClass)) {
                        try {
                            $warningHtml = app($warningClass)($this->record->sender_name, $groupIds);
                            if ($warningHtml) {
                                // Strip HTML tags for plain-text modal description
                                $warningText = strip_tags($warningHtml);
                                $msg .= "\n\n⚠️ " . trim($warningText);
                            }
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::error('EditCampaign sender warning hook failed: ' . $e->getMessage());
                        }
                    }

                    $msg .= "\n\n" . __('Continue?');
                    return $msg;
                })
                ->action(function (): void {
                    $this->save();

                    if ($this->data['toggle_schedule_later'] ?? false) {
                        $this->scheduleCampaign();
                    } else {
                        $this->dispatchCampaign();
                    }
                })
                ->visible(fn (): bool => in_array($this->record->status, ['new', 'scheduled'])),

            DeleteAction::make(),
        ];
    }

    protected function scheduleCampaign(): void
    {
        $scheduledAt = $this->data['scheduled_at'] ?? null;

        if (!$scheduledAt) {
            Notification::make()
                ->title(__('Please select a schedule date and time'))
                ->danger()
                ->send();
            return;
        }

        if (\Carbon\Carbon::parse($scheduledAt)->isPast()) {
            Notification::make()
                ->title(__('Schedule date must be in the future'))
                ->danger()
                ->send();
            return;
        }

        $this->record->update([
            'status'       => 'scheduled',
            'scheduled_at' => $scheduledAt,
        ]);

        $formatted = \Carbon\Carbon::parse($scheduledAt)->format('M j, Y H:i') . ' (' . config('app.timezone') . ')';

        Notification::make()
            ->title(__('Campaign scheduled'))
            ->body(__('Campaign will be sent on :date', ['date' => $formatted]))
            ->success()
            ->send();

        $this->redirect($this->getResource()::getUrl('index'));
    }

    protected function dispatchCampaign(): void
    {
        $fresh = $this->record->fresh();

        // Atomic status guard: only proceed if campaign is still in an unsent state
        // Handles both 'new' and 'scheduled' campaigns (scheduled → send immediately with toggle OFF)
        $updated = Campaign::where('id', $fresh->id)
            ->whereIn('status', ['new', 'scheduled'])
            ->update(['status' => 'sending', 'sent_at' => now(), 'scheduled_at' => null]);

        if ($updated === 0) {
            Notification::make()
                ->title(__('Campaign already dispatched'))
                ->body(__('This campaign has already been sent or is currently sending.'))
                ->warning()
                ->send();
            $this->redirect($this->getResource()::getUrl('index'));
            return;
        }

        $groupIds = $fresh->audience_group_ids ?? [];
        $filters = $fresh->custom_field_filters ?? [];

        // Load all groups in a single query
        $groups = EmailAudienceGroup::whereIn('id', $groupIds)->get()->keyBy('id');

        // Validate all groups still exist
        $missing = collect($groupIds)->filter(fn ($id) => !$groups->has($id))->count();

        if ($missing > 0) {
            // Revert status back to 'new' since we can't send
            $this->record->update(['status' => 'new', 'sent_at' => null]);
            Notification::make()
                ->title(__('Some lists were deleted'))
                ->body(__(':count selected list(s) no longer exist. Remove them from step 2 and try again.', ['count' => $missing]))
                ->danger()
                ->send();
            return;
        }

        // Calculate total recipients with same filters as QueueEmailsForAudience
        $total = 0;
        foreach ($groups as $group) {
            $query = $group->audienceUsers()
                ->where('is_active', true)
                ->where('bounced', false)
                ->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('bounced_emails')
                        ->whereColumn('bounced_emails.email', 'audience_users.email');
                });
            CampaignFilterBuilder::applyFilters($query, $filters);
            $total += $query->count();
        }

        $this->record->update(['total_recipients' => $total]);

        // An SMS campaign goes down its own path: the e-mail job would try to
        // spool it through PMTA, which has no idea what a phone number is.
        $this->record->isSms()
            ? DispatchSmsCampaign::dispatch($this->record)
            : DispatchCampaign::dispatch($this->record);

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
                    // Read-only on an existing campaign. A form control here would
                    // let a save flip the channel, and the recorded cost and audience
                    // belong to the channel it was sent on.
                    Placeholder::make('channel_display')
                        ->label(__('Channel'))
                        ->content(fn (): string => $this->record?->isSms() ? __('SMS') : __('E-mail')),

                    TextInput::make('name')
                        ->label(__('Campaign Name'))
                        ->required(),

                    Placeholder::make('sender_data')
                        ->hiddenLabel()
                        ->content(function () {
                            $configs = json_encode(
                                collect(SenderResolver::all())->mapWithKeys(fn ($s) => [
                                    $s['name'] => [
                                        'n' => $s['from_name'] ?? '',
                                        'a' => $s['from_address'] ?? '',
                                        'r' => $s['reply_to'] ?? $s['from_address'] ?? '',
                                    ],
                                ])->toArray()
                            );
                            $js = "window.__sc=JSON.parse(\$el.dataset.sc);window.__fs=function(v){var s=window.__sc[v];if(!s)return;document.querySelectorAll('input').forEach(function(i){var d=i.id||'';if(d.includes('sender_display_name')){i.value=s.n;i.dispatchEvent(new Event('input',{bubbles:true}))}if(d.includes('sender_address')){i.value=s.a;i.dispatchEvent(new Event('input',{bubbles:true}))}if(d.includes('reply_to')){i.value=s.r;i.dispatchEvent(new Event('input',{bubbles:true}))}})}";

                            return new HtmlString(
                                '<div x-data x-init="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '" data-sc="' . htmlspecialchars($configs, ENT_QUOTES, 'UTF-8') . '" style="display:none"></div>'
                            );
                        }),

                    Select::make('sender_name')
                        ->visible(fn (): bool => !($this->record?->isSms() ?? false))
                        ->label(__('Sender'))
                        ->options(fn () => SenderResolver::options())
                        ->required(fn (): bool => !($this->record?->isSms() ?? false))
                        ->extraAttributes(['x-on:change' => 'if(window.__fs)window.__fs($event.target.value)']),

                    // An SMS has no From address and nothing to reply to; the
                    // recipient sees the sender id configured for the provider.
                    // Left visible these were not merely noise: being required,
                    // they made an SMS campaign unsaveable on this screen.
                    TextInput::make('sender_display_name')
                        ->label(__('From Name'))
                        ->visible(fn (): bool => !($this->record?->isSms() ?? false))
                        ->required(fn (): bool => !($this->record?->isSms() ?? false)),

                    TextInput::make('sender_address')
                        ->label(__('From Address'))
                        ->email()
                        ->visible(fn (): bool => !($this->record?->isSms() ?? false))
                        ->required(fn (): bool => !($this->record?->isSms() ?? false)),

                    TextInput::make('reply_to')
                        ->label(__('Reply-To'))
                        ->email()
                        ->visible(fn (): bool => !($this->record?->isSms() ?? false))
                        ->helperText(__('Leave empty to use From Address'))
                        ->nullable(),

                    // What the recipient actually sees on an SMS.
                    Placeholder::make('sms_originator')
                        ->label(__('Sender ID'))
                        ->visible(fn (): bool => $this->record?->isSms() ?? false)
                        ->content(fn (): string => (string) config('email-system.sms.originator', '—')),
                ])
                ->afterValidation(function () {
                    $this->saveStepData();
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
                    $this->saveStepData();
                }),

            // ─── Step 3: Template ─────────────────────────────────────────────
            Step::make(__('Template'))
                ->icon('heroicon-o-document-text')
                ->schema([
                    Textarea::make('body')
                        ->visible(fn (): bool => $this->record?->isSms() ?? false)
                        ->required(fn (): bool => $this->record?->isSms() ?? false)
                        ->label(__('Message'))
                        ->rows(6)
                        ->live(onBlur: true)
                        ->helperText(__('Placeholders: {{name}}. Links are shortened automatically, one per recipient. No unsubscribe link: opt-out is the STOP keyword.')),

                    Placeholder::make('sms_meter')
                        ->visible(fn (): bool => $this->record?->isSms() ?? false)
                        ->label(__('Length and cost'))
                        ->content(function (Get $get): HtmlString {
                            $body = (string) $get('body');
                            if (trim($body) === '') {
                                return new HtmlString('<span class="text-gray-500">' . __('Type a message to see its length and cost.') . '</span>');
                            }

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
                        ->visible(fn (): bool => !($this->record?->isSms() ?? false))
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
                        ->label(__('Content Type'))
                        ->visible(fn (): bool => !($this->record?->isSms() ?? false))
                        ->options([
                            'both' => __('Both (HTML + Text)'),
                            'html' => __('HTML'),
                            'text' => __('Plain Text'),
                        ])
                        ->default('both')
                        ->required(fn (): bool => !($this->record?->isSms() ?? false))
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set, ?string $old, ?string $state) => ContentTypeConverter::handleContentTypeSwitch($get, $set, $old, $state)),

                    Hidden::make('_html_body_cache')->dehydrated(false),
                    Hidden::make('_text_body_cache')->dehydrated(false),

                    // Everything below belongs to an e-mail: an SMS has no
                    // subject, no HTML body and no A/B variations. Worse than
                    // clutter, the editor binds to the same `body` field as the
                    // SMS message box, so leaving it on screen let it overwrite
                    // the message with markup.
                    TextInput::make('subject')
                        ->label(__('Subject'))
                        ->visible(fn (): bool => !($this->record?->isSms() ?? false))
                        ->required(fn (): bool => !($this->record?->isSms() ?? false)),

                    $this->buildPlaceholderHint()
                        ->visible(fn (): bool => !($this->record?->isSms() ?? false)),

                    Field::make('body')
                        ->visible(fn (): bool => !($this->record?->isSms() ?? false))
                        ->label(__('Email Body'))
                        ->view('email-system::forms.tinymce')
                        ->extraAttributes(fn (Get $get) => [
                            'height' => 500,
                            'contentType' => $get('content_type') ?? 'html',
                        ])
                        ->columnSpanFull()
                        ->dehydrated(true)
                        ->dehydrateStateUsing(fn ($state) => $state)
                        ->required(fn (): bool => !($this->record?->isSms() ?? false)),

                    Section::make(__('Variations'))
                        ->visible(fn (): bool => !($this->record?->isSms() ?? false))
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
                    $this->saveStepData();
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
                            ' <a href="' . route('email-system.campaign.preview', $this->record->id) . '" target="_blank" class="text-primary-600 underline">' . __('Web Preview') . '</a>' .
                            '</p>'
                        )),

                    TextInput::make('test_email')
                        ->label(__('Test Email Address'))
                        ->visible(fn (): bool => !($this->record?->isSms() ?? false))
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
                    $this->saveStepData();
                }),

            // ─── Step 5: Confirm & Send ──────────────────────────────────────
            Step::make(__('Send'))
                ->icon('heroicon-o-paper-airplane')
                ->schema([
                    Placeholder::make('send_summary')
                        ->label('')
                        ->content(fn (Get $get): HtmlString => \JanDev\EmailSystem\Support\CampaignSummaryBuilder::build($get))
                        ->columnSpanFull(),

                    Toggle::make('toggle_schedule_later')
                        ->label(__('Schedule for later'))
                        ->helperText(__('Send the campaign automatically at a future date and time'))
                        ->afterStateHydrated(fn (Toggle $component) => $component->state($this->record->status === 'scheduled'))
                        ->live()
                        ->dehydrated(false),

                    DateTimePicker::make('scheduled_at')
                        ->label(__('Schedule Date & Time'))
                        ->minDate(fn () => now()->addMinutes(5))
                        ->helperText(__('Times are in :tz', ['tz' => config('app.timezone')]))
                        ->hidden(fn (Get $get) => !$get('toggle_schedule_later'))
                        ->required(fn (Get $get) => (bool) $get('toggle_schedule_later')),

                    Placeholder::make('schedule_countdown')
                        ->label('')
                        ->content(function (): HtmlString {
                            $scheduledAt = $this->record->scheduled_at;
                            if (!$scheduledAt || $this->record->status !== 'scheduled') {
                                return new HtmlString('');
                            }

                            $dateStr = e($scheduledAt->format('M j, Y H:i'));
                            $tz = e(config('app.timezone'));
                            $remaining = max(0, $scheduledAt->timestamp - now()->timestamp);
                            $expired = $remaining <= 0 ? 'true' : 'false';

                            $js = "r:{$remaining},d:'',x:{$expired},"
                                . "init(){this.t();setInterval(()=>this.t(),1000)},"
                                . "t(){if(this.r<=0){this.x=true;return}"
                                . "let r=this.r,dd=Math.floor(r/86400),h=Math.floor((r%86400)/3600),"
                                . "m=Math.floor((r%3600)/60),s=r%60,"
                                . "p=n=>n<10?'0'+n:n,a=[];"
                                . "if(dd>0)a.push(dd+'d');"
                                . "a.push(p(h)+':'+p(m)+':'+p(s));"
                                . "this.d=a.join(' ');this.r--}";

                            return new HtmlString(
                                '<div x-data="{' . $js . '}" class="flex items-center gap-2 text-sm">'
                                . '<span style="color:#6b7280">' . e(__('Scheduled for')) . ' ' . $dateStr . ' <span style="color:#9ca3af">' . $tz . '</span></span>'
                                . ' &mdash; '
                                . '<span x-show="!x" x-text="d" style="color:#3b82f6;font-weight:600"></span>'
                                . '<span x-show="x" style="color:#3b82f6;font-weight:600">' . e(__('Dispatching shortly...')) . '</span>'
                                . '</div>'
                            );
                        })
                        ->hidden(fn (Get $get) => !$get('toggle_schedule_later'))
                        ->columnSpanFull(),
                ]),
        ];
    }

    protected function buildPlaceholderHint(): Placeholder
    {
        return \JanDev\EmailSystem\Support\PlaceholderHint::make();
    }

    protected function saveStepData(): void
    {
        try {
            $data = $this->data;
            $this->record->update([
                'name'                => $data['name'] ?? $this->record->name,
                'sender_name'         => $data['sender_name'] ?? $this->record->sender_name,
                'sender_address'      => $data['sender_address'] ?? $this->record->sender_address,
                'sender_display_name' => $data['sender_display_name'] ?? $this->record->sender_display_name,
                'reply_to'            => $data['reply_to'] ?? $this->record->reply_to,
                'audience_group_ids'    => $data['audience_group_ids'] ?? $this->record->audience_group_ids,
                'custom_field_filters' => $data['custom_field_filters'] ?? $this->record->custom_field_filters,
                'skip_providers'       => $data['skip_providers'] ?? $this->record->skip_providers,
                'email_template_id'   => $data['email_template_id'] ?? $this->record->email_template_id,
                'content_type'        => $data['content_type'] ?? $this->record->content_type,
                'subject'             => $data['subject'] ?? $this->record->subject,
                'body'                => $data['body'] ?? $this->record->body,
                'variations'          => $data['variations'] ?? $this->record->variations,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Campaign step save failed: ' . $e->getMessage());
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['current_step'] = 5;

        // Handle schedule toggle (toggle is dehydrated=false, read from Livewire data)
        $toggleOn = $this->data['toggle_schedule_later'] ?? false;
        if ($toggleOn && !empty($data['scheduled_at'])) {
            $data['status'] = 'scheduled';
        } else {
            $data['scheduled_at'] = null;
            if ($this->record->status === 'scheduled') {
                $data['status'] = 'new';
            }
        }

        return $data;
    }

    public function getSubmitFormActionLabel(): string
    {
        return __('Save Campaign');
    }
    protected function getSavedNotificationTitle(): ?string
    {
        if ($this->record->status === 'scheduled' && $this->record->scheduled_at) {
            return __('Campaign scheduled for :time', [
                'time' => $this->record->scheduled_at->format('M j, Y H:i'),
            ]);
        }

        return __('Campaign saved');
    }
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

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
            'campaign_id'          => $this->record->id,
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
}
