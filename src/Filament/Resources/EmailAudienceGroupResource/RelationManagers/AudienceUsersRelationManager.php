<?php

namespace JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource\RelationManagers;

use JanDev\EmailSystem\Jobs\VerifyEmailsZeroBounceJob;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Services\ZeroBounce;
use JanDev\EmailSystem\Support\CustomFieldComponents;
use JanDev\EmailSystem\Support\CsvHelper;
use JanDev\EmailSystem\Support\Sms\SmsPhone;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

use function JanDev\EmailSystem\resolve_callback;

class AudienceUsersRelationManager extends RelationManager
{
    /** @var array<int, string> CSV column headers detected from uploaded file */
    public array $csvColumnOptions = [];
    /** @var bool Whether the uploaded CSV has a header row */
    public bool $csvHasHeader = true;
    protected static string $relationship = 'audienceUsers';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->label(__('User Name'))
                    ->required(),

                TextInput::make('email')
                    ->label(__('Email'))
                    ->email()
                    ->required()
                    ->autocomplete('new-password')
                    ->id('edit_user_addr')
                    ->extraInputAttributes([
                        'data-1p-ignore' => 'true',
                        'data-lpignore' => 'true',
                        'autocomplete' => 'new-password',
                    ]),

                TextInput::make('phone')
                    ->label(__('Phone'))
                    ->tel()
                    ->nullable()
                    ->helperText(__('International format with the country code, e.g. +447700900123. Without it the provider guesses the country, which is a wasted message at the wrong price.'))
                    ->rule('nullable')
                    ->dehydrateStateUsing(fn (?string $state): ?string => \JanDev\EmailSystem\Support\Sms\SmsPhone::normalise($state))
                    ->rules([
                        fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                            if ($value !== null && $value !== '' && \JanDev\EmailSystem\Support\Sms\SmsPhone::normalise($value) === null) {
                                $fail(__('Use international format, starting with +.'));
                            }
                        },
                    ]),

                Toggle::make('is_active')
                    ->label(__('Active Status'))
                    ->inline(false),

                ...CustomFieldComponents::formFields(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(__('Falsely bounced? See docs/bounce-false-positives.md — use the Restore from Bounced action on affected rows.'))
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('name')->label(__('User Name')),
                TextColumn::make('email')->label(__('Email')),
                TextColumn::make('created_at')->label(__('Added At'))->dateTime('Y-m-d H:i:s'),

                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean()
                    ->trueIcon('heroicon-s-check-circle')
                    ->falseIcon('heroicon-s-x-circle'),

                TextColumn::make('zerobounce_status')
                    ->label(__('ZeroBounce'))
                    ->badge()
                    ->color(fn (?string $state): string => ZeroBounce::getStatusColor($state ?? 'unverified'))
                    ->formatStateUsing(fn (?string $state, AudienceUser $record): string =>
                        ZeroBounce::getStatusLabelWithSubStatus($state ?? 'unverified', $record->zerobounce_sub_status)
                    )
                    ->visible(fn () => ZeroBounce::isEnabled()),

                TextColumn::make('bounce_reason')
                    ->label(__('Bounce Reason'))
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->toggleable(isToggledHiddenByDefault: true),

                ...CustomFieldComponents::tableColumns(),
            ])
            ->filters([
                Filter::make('name')
                    ->label(__('Name'))
                    ->query(function ($query, $data) {
                        return $query->where('name', 'like', '%' . $data['name'] . '%');
                    })
                    ->schema([
                        TextInput::make('name')
                            ->placeholder(__('Search by name')),
                    ]),

                Filter::make('email')
                    ->label(__('Email'))
                    ->query(function ($query, $data) {
                        return $query->where('email', 'like', '%' . $data['email'] . '%');
                    })
                    ->schema([
                        TextInput::make('email')
                            ->placeholder(__('Search by email'))
                            ->autocomplete('new-password')
                            ->id('rel_filter_addr')
                            ->extraInputAttributes([
                                'data-1p-ignore' => 'true',
                                'data-lpignore' => 'true',
                                'autocomplete' => 'new-password',
                            ]),
                    ]),

                SelectFilter::make('is_active')
                    ->label(__('Active Status'))
                    ->options([
                        1 => __('Active'),
                        0 => __('Inactive'),
                    ])
                    ->placeholder(__('All Statuses')),

                SelectFilter::make('zerobounce_status')
                    ->label(__('ZeroBounce Status'))
                    ->options([
                        'unverified' => __('Unverified'),
                        'valid'      => __('Valid'),
                        'catch_all'  => __('Catch-All'),
                        'unknown'    => __('Unknown'),
                        'invalid'    => __('Invalid'),
                        'bounced'    => __('Bounced'),
                    ])
                    ->placeholder(__('All ZB Statuses'))
                    ->visible(fn () => ZeroBounce::isEnabled()),

                ...CustomFieldComponents::tableFilters(),
            ])
            ->headerActions([
                Action::make('downloadFilteredCsv')
                    ->label(__('Download CSV'))
                    ->action(function () {
                        $filteredQuery = $this->getFilteredTableQuery();

                        return response()->streamDownload(function () use ($filteredQuery) {
                            $handle = fopen('php://output', 'w');
                            fputcsv($handle, CsvHelper::buildHeader(), ';');

                            $definitions = \JanDev\EmailSystem\Models\AudienceUser::getCustomFieldDefinitions();
                            foreach ($filteredQuery->get() as $user) {
                                fputcsv($handle, CsvHelper::buildRow($user, $definitions), ';');
                            }

                            fclose($handle);
                        }, __('filtered_subscribers.csv'), [
                            'Content-Type' => 'text/csv',
                            'Content-Disposition' => 'attachment; filename="filtered_audience_users.csv"',
                        ]);
                    })
                    ->icon('heroicon-o-arrow-down-tray')
                    ->requiresConfirmation(__('Download filtered subscribers as CSV?')),

                Action::make('addUser')
                    ->label(__('Add User'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('User Name'))
                            ->required(),
                        TextInput::make('email')
                            ->label(__('Email'))
                            ->email()
                            ->required()
                            ->autocomplete('new-password')
                            ->id('add_user_addr')
                            ->extraInputAttributes([
                                'data-1p-ignore' => 'true',
                                'data-lpignore' => 'true',
                                'autocomplete' => 'new-password',
                            ]),
                    ])
                    ->action(function (array $data) {
                        $groupId = $this->getOwnerRecord()->id;

                        $exists = AudienceUser::where('email', $data['email'])
                            ->where('email_audience_group_id', $groupId)
                            ->exists();

                        if ($exists) {
                            Notification::make()
                                ->title(__('User Already Exists'))
                                ->body(__('This user is already in this list.'))
                                ->warning()
                                ->send();
                            return;
                        }

                        AudienceUser::create([
                            'name' => $data['name'],
                            'email' => $data['email'],
                            'phone' => \JanDev\EmailSystem\Support\Sms\SmsPhone::normalise($data['phone'] ?? null),
                            'is_active' => true,
                            'email_audience_group_id' => $groupId,
                        ]);

                        Notification::make()
                            ->title(__('User Added'))
                            ->body(__('The user has been successfully added to the group.'))
                            ->success()
                            ->send();
                    })
                    ->icon('heroicon-o-user-plus')
                    ->requiresConfirmation(__('Are you sure you want to add this user?')),

                // Add all subscribed users - uses config callback
                Action::make('addAllSubscribedToGroup')
                    ->label(__('Add All Subscribed'))
                    ->visible(fn () => resolve_callback(config('email-system.add_subscribed_users_callback')) !== null)
                    ->action(function () {
                        $groupId = $this->getOwnerRecord()->id;
                        $callback = resolve_callback(config('email-system.add_subscribed_users_callback'));
                        $subscribedUsers = $callback();
                        $addedCount = 0;

                        foreach ($subscribedUsers as $user) {
                            $exists = AudienceUser::where('email', $user->email)
                                ->where('email_audience_group_id', $groupId)
                                ->exists();

                            if (!$exists) {
                                AudienceUser::create([
                                    'name' => $user->name,
                                    'email' => $user->email,
                                    'is_active' => true,
                                    'email_audience_group_id' => $groupId,
                                ]);
                                $addedCount++;
                            }
                        }

                        Notification::make()
                            ->title(__('Success'))
                            ->body($addedCount . ' ' . __('users added'))
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation(__('Are you sure you want to add all subscribed users?'))
                    ->icon('heroicon-o-plus'),

                // Add users by registration date range - uses config callback
                Action::make('addUsersByDateRange')
                    ->label(__('Add Users by Date'))
                    ->visible(fn () => resolve_callback(config('email-system.add_users_by_date_callback')) !== null)
                    ->schema([
                        DatePicker::make('date_from')
                            ->label(__('Date From'))
                            ->required()
                            ->native(false)
                            ->displayFormat('Y-m-d'),
                        DatePicker::make('date_to')
                            ->label(__('Date To'))
                            ->required()
                            ->native(false)
                            ->displayFormat('Y-m-d'),
                    ])
                    ->action(function (array $data) {
                        $groupId = $this->getOwnerRecord()->id;
                        $addedCount = 0;
                        $skippedCount = 0;

                        $callback = resolve_callback(config('email-system.add_users_by_date_callback'));
                        $users = $callback($data['date_from'], $data['date_to']);

                        foreach ($users as $user) {
                            $exists = AudienceUser::where('email', $user->email)
                                ->where('email_audience_group_id', $groupId)
                                ->exists();

                            if ($exists) {
                                $skippedCount++;
                                continue;
                            }

                            AudienceUser::create([
                                'name' => $user->name,
                                'email' => $user->email,
                                'is_active' => true,
                                'email_audience_group_id' => $groupId,
                            ]);
                            $addedCount++;
                        }

                        Notification::make()
                            ->title(__('Success'))
                            ->body(__('Added: :added, Skipped: :skipped', ['added' => $addedCount, 'skipped' => $skippedCount]))
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation(__('Are you sure you want to add users in this date range?'))
                    ->icon('heroicon-o-calendar-days'),

                // ZeroBounce email verification
                Action::make('verifyZerobounce')
                    ->label(__('Verify Emails (ZeroBounce)'))
                    ->icon('heroicon-o-shield-check')
                    ->visible(fn () => ZeroBounce::isEnabled())
                    ->requiresConfirmation()
                    ->modalHeading(__('Verify Emails with ZeroBounce'))
                    ->modalDescription(function () {
                        $groupId = $this->getOwnerRecord()->id;
                        $count = AudienceUser::where('email_audience_group_id', $groupId)
                            ->where('zerobounce_status', 'unverified')
                            ->count();
                        $desc = __('This will verify :count unverified email address(es). Each verification uses 1 ZeroBounce credit.', ['count' => $count]);
                        if (config('queue.default') === 'sync') {
                            $desc .= "\n\n⚠️ " . __('WARNING: Queue is set to sync. This job will block the HTTP request and may time out for large groups. Please configure a proper queue driver (e.g. database) first.');
                        }
                        return $desc;
                    })
                    ->action(function () {
                        if (config('queue.default') === 'sync') {
                            Notification::make()
                                ->title(__('Queue Not Configured'))
                                ->body(__('Queue is set to sync. Please configure a proper queue driver (e.g. database) before running ZeroBounce verification to avoid blocking the HTTP request.'))
                                ->danger()
                                ->send();
                            return;
                        }

                        $groupId = $this->getOwnerRecord()->id;
                        $count = AudienceUser::where('email_audience_group_id', $groupId)
                            ->where('zerobounce_status', 'unverified')
                            ->count();

                        VerifyEmailsZeroBounceJob::dispatch($groupId, auth()->id());

                        $credits = null;
                        try {
                            $credits = ZeroBounce::getCredits();
                        } catch (\Throwable) {
                            // Credits check is informational — never block the dispatch
                        }

                        if ($credits !== null && $credits === 0) {
                            Notification::make()
                                ->title(__('Warning: No ZeroBounce Credits'))
                                ->body(__('Your ZeroBounce account has 0 credits. The verification job was dispatched but emails will not be verified until you purchase more credits.'))
                                ->warning()
                                ->send();
                        } else {
                            $creditsText = ($credits !== null)
                                ? ' ' . __('Remaining credits: :credits', ['credits' => $credits])
                                : '';

                            Notification::make()
                                ->title(__('Verification Started'))
                                ->body(__('ZeroBounce verification job dispatched for :count email(s).', ['count' => $count]) . $creditsText)
                                ->success()
                                ->send();
                        }
                    }),

                Action::make('removeBounceStatus')
                    ->label(__('Remove Bounce Status'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('Remove All Bounce Status'))
                    ->modalDescription(function () {
                        $groupId = $this->getOwnerRecord()->id;
                        $bouncedCount = AudienceUser::where('email_audience_group_id', $groupId)
                            ->where(function ($q) {
                                $q->where('bounced', true)
                                    ->orWhere('zerobounce_status', 'bounced');
                            })
                            ->count();

                        return __('This will clear bounce status for :count subscriber(s) and reset their ZeroBounce status to unverified so they can be re-verified.', ['count' => $bouncedCount]);
                    })
                    ->action(function () {
                        $groupId = $this->getOwnerRecord()->id;

                        // Collect bounced emails before updating
                        $bouncedEmails = AudienceUser::where('email_audience_group_id', $groupId)
                            ->where(function ($q) {
                                $q->where('bounced', true)
                                    ->orWhere('zerobounce_status', 'bounced');
                            })
                            ->pluck('email')
                            ->map(fn ($e) => strtolower($e))
                            ->unique()
                            ->values()
                            ->all();

                        $affected = AudienceUser::where('email_audience_group_id', $groupId)
                            ->where(function ($q) {
                                $q->where('bounced', true)
                                    ->orWhere('zerobounce_status', 'bounced');
                            })
                            ->update([
                                'bounced' => false,
                                'bounce_type' => null,
                                'bounce_reason' => null,
                                'bounced_at' => null,
                                'is_active' => true,
                                'zerobounce_status' => 'unverified',
                                'zerobounce_sub_status' => null,
                                'zerobounce_checked_at' => null,
                            ]);

                        // Also remove from global bounce registry so they won't be filtered during sending
                        if (!empty($bouncedEmails)) {
                            DB::table('bounced_emails')->whereIn('email', $bouncedEmails)->delete();
                        }

                        Notification::make()
                            ->title(__('Bounce Status Removed'))
                            ->body(__(':count subscriber(s) updated and removed from bounce registry. You can now re-verify them with ZeroBounce.', ['count' => $affected]))
                            ->success()
                            ->send();
                    }),

                // Upload CSV with column mapping
                Action::make('uploadCsv')
                    ->label(__('Upload CSV'))
                    ->schema(fn (): array => $this->buildCsvUploadForm())
                    ->action(function (array $data) {
                        $this->processCsvWithMapping($data);
                    })
                    ->modalWidth('lg')
                    ->icon('heroicon-o-arrow-up-tray'),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('setZeroBounceStatus')
                    ->label(__('Set ZB Status'))
                    ->icon('heroicon-o-shield-check')
                    ->color('info')
                    ->schema([
                        Select::make('zerobounce_status')
                            ->label(__('New ZeroBounce Status'))
                            ->options([
                                'unverified' => __('Unverified'),
                                'valid'      => __('Valid'),
                                'catch_all'  => __('Catch-All'),
                                'unknown'    => __('Unknown'),
                                'invalid'    => __('Invalid'),
                                // 'bounced' intentionally omitted — use Restore action for bounce state
                            ])
                            ->default(fn (AudienceUser $record) => $record->zerobounce_status ?? 'unverified')
                            ->required(),
                    ])
                    ->modalHeading(fn (AudienceUser $record) => __('Override ZB status for :email', ['email' => $record->email]))
                    ->modalDescription(__('ZeroBounce status is informational. Emails are only blocked from sending when the Bounced flag is set by a real SMTP/webhook bounce — use the Restore action to clear that flag.'))
                    ->action(function (array $data, AudienceUser $record) {
                        $record->update([
                            'zerobounce_status'     => $data['zerobounce_status'],
                            'zerobounce_sub_status' => null,
                            'zerobounce_checked_at' => now(),
                        ]);
                        Notification::make()
                            ->title(__('ZB status updated'))
                            ->body($record->email . ' → ' . $data['zerobounce_status'])
                            ->success()
                            ->send();
                    })
                    ->visible(fn (): bool => ZeroBounce::isEnabled()),

                Action::make('restoreFromBounced')
                    ->label(__('Restore from Bounced'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (AudienceUser $record): bool => (bool) $record->bounced || $record->zerobounce_status === 'bounced')
                    ->requiresConfirmation()
                    ->modalDescription(__('Clear bounce flags across ALL groups for this email, and remove the email from the global bounce registry so re-imports are not re-flagged.'))
                    ->action(function (AudienceUser $record) {
                        $email = strtolower($record->email);
                        DB::transaction(function () use ($email) {
                            AudienceUser::where('email', $email)->update([
                                'bounced'               => false,
                                'bounce_type'           => null,
                                'bounce_reason'         => null,
                                'bounced_at'            => null,
                                'is_active'             => true,
                                'zerobounce_status'     => 'unverified',
                                'zerobounce_sub_status' => null,
                                'zerobounce_checked_at' => now(),
                            ]);
                            DB::table('bounced_emails')->where('email', $email)->delete();
                        });
                        Notification::make()
                            ->title(__('Restored'))
                            ->body($record->email)
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),

                BulkAction::make('restoreBouncedBulk')
                    ->label(__('Restore from Bounced'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('Restore ALL selected subscribers plus any other groups sharing their email; removes from bounce registry.'))
                    ->action(function (Collection $records) {
                        $emails = $records->pluck('email')
                            ->map(fn ($e) => strtolower($e))
                            ->unique()
                            ->values();
                        $restored = 0;
                        $emails->chunk(200)->each(function (Collection $chunk) use (&$restored) {
                            DB::transaction(function () use ($chunk, &$restored) {
                                $list = $chunk->all();
                                $restored += AudienceUser::whereIn('email', $list)->update([
                                    'bounced'               => false,
                                    'bounce_type'           => null,
                                    'bounce_reason'         => null,
                                    'bounced_at'            => null,
                                    'is_active'             => true,
                                    'zerobounce_status'     => 'unverified',
                                    'zerobounce_sub_status' => null,
                                    'zerobounce_checked_at' => now(),
                                ]);
                                DB::table('bounced_emails')->whereIn('email', $list)->delete();
                            });
                        });
                        Notification::make()
                            ->title(__('Restored'))
                            ->body(__(':count subscriber row(s) restored across :emails unique email(s)', [
                                'count'  => $restored,
                                'emails' => $emails->count(),
                            ]))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * Build the CSV upload form with FileUpload + mapping selects.
     * afterStateUpdated reads the TemporaryUploadedFile, detects headers,
     * stores them in a Livewire property, and auto-fills mapping selects via $set.
     */
    private function buildCsvUploadForm(): array
    {
        $fields = [
            FileUpload::make('csv_file')
                ->label(__('Select CSV File'))
                ->disk('local')
                ->directory('csv-uploads')
                ->required()
                ->live()
                ->afterStateUpdated(function (mixed $state, Set $set) {
                    $this->csvColumnOptions = [];

                    if (!$state) {
                        return;
                    }

                    // Resolve the real file path from TemporaryUploadedFile or string
                    $fullPath = $this->resolveUploadedFilePath($state);
                    if (!$fullPath) {
                        return;
                    }

                    $detected = CsvHelper::detectHeaders($fullPath);
                    if (!$detected) {
                        return;
                    }

                    // Store header detection result and set the toggle
                    $this->csvHasHeader = $detected['has_header'];
                    $set('csv_has_header', $detected['has_header']);

                    // Store options in Livewire property so Select closures can read them
                    $options = ['' => __('-- Skip --')];
                    foreach ($detected['headers'] as $i => $header) {
                        $options[(string) $i] = $header;
                    }
                    $this->csvColumnOptions = $options;

                    // Auto-detect and pre-fill mapping selects
                    // Track claimed column indices so no column is mapped twice
                    $headers = $detected['headers'];
                    $claimedIndices = [];

                    $nameIdx = CsvHelper::autoDetectColumn($headers, [
                        'name', 'név', 'nev', 'username', 'user_name', 'full_name',
                        'fullname', 'teljes_nev', 'teljes_név', 'felhasználónév',
                        'first_name', 'keresztnév', 'contact_name', 'display_name',
                    ], $claimedIndices);
                    $emailIdx = CsvHelper::autoDetectColumn($headers, [
                        'email', 'e-mail', 'emailcim', 'email_cím', 'mail',
                        'email_address', 'emailaddress', 'e_mail', 'e_mail_cim',
                        'email_cim', 'user_email', 'contact_email',
                    ], $claimedIndices);
                    // If only 2 columns and email was detected but name wasn't,
                    // assign the other column as name
                    if (count($headers) === 2 && $emailIdx !== null && $nameIdx === null) {
                        $nameIdx = $emailIdx === 0 ? 1 : 0;
                    }

                    if ($nameIdx !== null) {
                        $set('map_name', $nameIdx);
                        $claimedIndices[] = $nameIdx;
                    }
                    if ($emailIdx !== null) {
                        $set('map_email', $emailIdx);
                        $claimedIndices[] = $emailIdx;
                    }

                    $phoneIdx = CsvHelper::autoDetectColumn($headers, CsvHelper::PHONE_ALIASES, $claimedIndices);
                    if ($phoneIdx !== null) {
                        $set('map_phone', $phoneIdx);
                        $claimedIndices[] = $phoneIdx;
                    }

                    $zbIdx = CsvHelper::autoDetectColumn($headers, [
                        'zerobounce_status', 'zerobounce', 'zb_status', 'zb',
                        'zerobounce_statusz', 'bounce_status', 'email_status',
                        'verification_status', 'email_verification',
                    ], $claimedIndices);
                    if ($zbIdx !== null) {
                        $set('map_zerobounce_status', $zbIdx);
                        $claimedIndices[] = $zbIdx;
                    }

                    $countryDetected = false;
                    $currencyDetected = false;
                    foreach (AudienceUser::getCustomFieldDefinitions() as $def) {
                        $slug = $def['slug'] ?? null;
                        $defName = $def['name'] ?? null;
                        if (!$slug) {
                            continue;
                        }
                        $aliases = [$slug];
                        if ($defName) {
                            $aliases[] = $defName;
                        }
                        $idx = CsvHelper::autoDetectColumn($headers, $aliases, $claimedIndices);
                        if ($idx !== null) {
                            $set('map_cf_' . $slug, $idx);
                            $claimedIndices[] = $idx;
                            if ($slug === 'country') {
                                $countryDetected = true;
                            }
                            if ($slug === 'currency') {
                                $currencyDetected = true;
                            }
                        }
                    }

                    // No default country/currency — user sets them manually if needed
                }),

            Toggle::make('csv_has_header')
                ->label(__('First row is header (skip it)'))
                ->inline(false)
                ->default(true)
                ->live()
                ->visible(fn (): bool => !empty($this->csvColumnOptions))
                ->helperText(fn (Get $get) => ($get('csv_has_header') ?? true)
                    ? __('The first row will be skipped.')
                    : __('All rows will be imported as data.')),

            Select::make('map_name')
                ->label(__('Name'))
                ->options(fn (): array => $this->csvColumnOptions)
                ->visible(fn (): bool => !empty($this->csvColumnOptions))
                ->required(fn (): bool => !empty($this->csvColumnOptions)),

            // Email and phone are each optional on their own, but a row needs at
            // least one of them — an SMS list has numbers and no addresses, an
            // e-mail list the other way round. The per-row rule is enforced in
            // the importer (CsvHelper::contactError); requiring the column here
            // would reject a valid phone-only file before it is even read.
            Select::make('map_email')
                ->label(__('Email'))
                ->options(fn (): array => $this->csvColumnOptions)
                ->visible(fn (): bool => !empty($this->csvColumnOptions))
                ->helperText(__('Leave empty for an SMS-only list.')),

            Select::make('map_phone')
                ->label(__('Phone'))
                ->options(fn (): array => $this->csvColumnOptions)
                ->visible(fn (): bool => !empty($this->csvColumnOptions))
                ->helperText(__('Required for SMS campaigns. Numbers are normalised on import.')),

        ];

        // Build extra fields for the collapsible section
        $extraFields = [
            Select::make('map_zerobounce_status')
                ->label(__('ZeroBounce Status'))
                ->options(fn (): array => $this->csvColumnOptions),
        ];

        $hasCountryField = false;
        $hasCurrencyField = false;
        foreach (AudienceUser::getCustomFieldDefinitions() as $def) {
            $slug = $def['slug'] ?? null;
            if (!$slug) {
                continue;
            }
            $select = Select::make('map_cf_' . $slug)
                ->label($def['name'] ?? $slug)
                ->options(fn (): array => $this->csvColumnOptions);

            if ($slug === 'country') {
                $select = $select->live();
                $hasCountryField = true;
            }

            if ($slug === 'currency') {
                $select = $select->live();
                $hasCurrencyField = true;
            }

            $extraFields[] = $select;
        }

        if ($hasCountryField) {
            $extraFields[] = Select::make('default_country')
                ->label(__('Default Country'))
                ->options(CsvHelper::getCountryOptions())
                ->searchable()
                ->live()
                ->helperText(__('Applied to all imported users when no country column is mapped'))
                ->visible(fn (Get $get): bool => ($get('map_cf_country') ?? '') === '')
                ->afterStateUpdated(function ($state, Get $get, Set $set) use ($hasCurrencyField) {
                    if ($hasCurrencyField && $state && ($get('map_cf_currency') ?? '') === '' && ($get('default_currency') ?? '') === '') {
                        $currency = CsvHelper::currencyForCountry($state);
                        if ($currency) {
                            $set('default_currency', $currency);
                        }
                    }
                });
        }

        if ($hasCurrencyField) {
            $extraFields[] = Select::make('default_currency')
                ->label(__('Default Currency'))
                ->options(CsvHelper::getCurrencyOptions())
                ->searchable()
                ->helperText(__('Applied to all imported users when no currency column is mapped. Auto-filled from country.'))
                ->visible(fn (Get $get): bool => ($get('map_cf_currency') ?? '') === '');
        }

        $fields[] = Section::make(__('Advanced Mapping'))
            ->collapsed()
            ->schema($extraFields)
            ->visible(fn (): bool => !empty($this->csvColumnOptions));

        return $fields;
    }

    /**
     * Resolve the real filesystem path from a FileUpload state value.
     * Handles TemporaryUploadedFile objects, arrays, and string paths.
     */
    private function resolveUploadedFilePath(mixed $state): ?string
    {
        // Handle array (Filament wraps state in array internally)
        if (is_array($state)) {
            $state = reset($state);
            if (!$state) {
                return null;
            }
        }

        // TemporaryUploadedFile from Livewire
        if ($state instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
            return $state->getRealPath();
        }

        // String path on the configured disk
        if (is_string($state)) {
            $fullPath = Storage::disk('local')->path($state);
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }

    /**
     * Process the uploaded CSV with user-defined column mapping.
     */
    private function processCsvWithMapping(array $data): void
    {
        $groupId = $this->getOwnerRecord()->id;

        $csvPath = Storage::disk('local')->path($data['csv_file']);

        if (!file_exists($csvPath)) {
            Notification::make()
                ->title(__('Error'))
                ->body(__('The uploaded file could not be found.'))
                ->danger()
                ->send();
            return;
        }

        $nameIdx = ($data['map_name'] ?? '') !== '' ? (int) $data['map_name'] : null;
        $emailIdx = ($data['map_email'] ?? '') !== '' ? (int) $data['map_email'] : null;
        $phoneIdx = ($data['map_phone'] ?? '') !== '' ? (int) $data['map_phone'] : null;
        $zbIdx = ($data['map_zerobounce_status'] ?? '') !== '' ? (int) $data['map_zerobounce_status'] : null;

        if ($nameIdx === null || $emailIdx === null) {
            Notification::make()
                ->title(__('Error'))
                ->body(__('Name and Email column mapping is required.'))
                ->danger()
                ->send();
            return;
        }

        // Build custom field index map
        $definitions = AudienceUser::getCustomFieldDefinitions();
        $defBySlug = collect($definitions)->keyBy('slug')->all();
        $cfMapping = [];

        foreach ($data as $key => $value) {
            if (str_starts_with($key, 'map_cf_') && $value !== '' && $value !== null) {
                $slug = substr($key, 7);
                if (isset($defBySlug[$slug])) {
                    $cfMapping[$slug] = [
                        'index' => (int) $value,
                        'type' => $defBySlug[$slug]['type'] ?? 'text',
                    ];
                }
            }
        }

        // Default country when no country column is mapped
        $defaultCountry = null;
        if (!isset($cfMapping['country']) && !empty($data['default_country']) && isset($defBySlug['country'])) {
            $defaultCountry = $data['default_country'];
        }

        // Default currency when no currency column is mapped
        $defaultCurrency = null;
        if (!isset($cfMapping['currency']) && isset($defBySlug['currency'])) {
            if (!empty($data['default_currency'])) {
                $defaultCurrency = $data['default_currency'];
            } elseif ($defaultCountry) {
                $defaultCurrency = CsvHelper::currencyForCountry($defaultCountry);
            }
        }

        // Read file, convert to UTF-8 if needed, and detect separator
        $rawContent = file_get_contents($csvPath);
        $encoding = mb_detect_encoding($rawContent, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $encoding);
        }
        $lines = explode("\n", $rawContent);
        Storage::disk('local')->delete($data['csv_file']);

        if (empty($lines)) {
            Notification::make()->title(__('Error'))->body(__('The CSV file is empty.'))->warning()->send();
            return;
        }

        $headerLine = trim(preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]));
        $semicolons = substr_count($headerLine, ';');
        $commas = substr_count($headerLine, ',');
        $separator = $semicolons >= $commas ? ';' : ',';

        $hasHeader = $data['csv_has_header'] ?? $this->csvHasHeader;
        if ($hasHeader) {
            array_shift($lines); // skip header row — first row contains labels
        }

        $addedCount = 0;
        $updatedCount = 0;
        $skippedInvalid = 0;
        $typeErrors = [];

        $validZbStatuses = ['unverified', 'valid', 'catch_all', 'unknown', 'invalid', 'bounced'];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $row = str_getcsv($line, $separator);
            $name = trim($row[$nameIdx] ?? '');
            $email = $emailIdx !== null ? trim($row[$emailIdx] ?? '') : '';
            $rawPhone = $phoneIdx !== null ? trim($row[$phoneIdx] ?? '') : '';
            $phone = $rawPhone !== '' ? SmsPhone::normalise($rawPhone) : null;

            // A row needs a name and at least one usable contact. Requiring an
            // e-mail would throw away every row of a phone-only SMS list.
            if ($name === '' || CsvHelper::contactError($email, $rawPhone) !== null) {
                $skippedInvalid++;
                continue;
            }

            // Parse zerobounce status
            $zbStatus = null;
            if ($zbIdx !== null) {
                $rawZb = strtolower(trim($row[$zbIdx] ?? ''));
                if ($rawZb !== '' && in_array($rawZb, $validZbStatuses, true)) {
                    $zbStatus = $rawZb;
                }
            }

            // Parse custom fields
            $customFields = [];
            foreach ($cfMapping as $slug => $mapping) {
                $rawValue = trim($row[$mapping['index']] ?? '');
                if ($rawValue === '') {
                    continue;
                }
                $parsed = CsvHelper::parseFieldValue($rawValue, $slug, $mapping['type']);
                if ($parsed['error']) {
                    $typeErrors[] = $parsed['error'];
                } else {
                    $customFields[$slug] = $parsed['value'];
                }
            }

            if ($defaultCountry && !isset($customFields['country'])) {
                $customFields['country'] = $defaultCountry;
            }

            if ($defaultCurrency && !isset($customFields['currency'])) {
                $customFields['currency'] = $defaultCurrency;
            }

            // Check if user already exists in this group — update instead of skip
            // Match on whichever contact this list actually has: an SMS list has
            // no addresses, so keying on e-mail alone would re-insert every
            // number on each import.
            $existingQuery = AudienceUser::where('email_audience_group_id', $groupId);
            if ($email !== '') {
                $existingQuery->where('email', $email);
            } else {
                $existingQuery->where('phone', $phone);
            }
            $existing = $existingQuery->first();

            if ($existing) {
                $updateData = ['name' => $name];
                // Only overwrite a stored number when the file actually carries
                // one: a re-import from an e-mail-only export must not wipe it.
                if ($phone !== null) {
                    $updateData['phone'] = $phone;
                }
                if (!empty($customFields)) {
                    $updateData['custom_fields'] = array_merge($existing->custom_fields ?? [], $customFields);
                }
                if ($zbStatus !== null) {
                    $updateData['zerobounce_status'] = $zbStatus;
                    $updateData['zerobounce_checked_at'] = now();
                }
                // Normalize: bounced users must have zerobounce_status = 'bounced'
                if ($existing->bounced && ($existing->zerobounce_status !== 'bounced') && $zbStatus === null) {
                    $updateData['zerobounce_status'] = 'bounced';
                }
                $existing->update($updateData);
                $updatedCount++;
                continue;
            }

            $createData = [
                'name' => $name,
                'email' => $email !== '' ? $email : null,
                'phone' => $phone,
                'is_active' => true,
                'email_audience_group_id' => $groupId,
                'custom_fields' => $customFields,
            ];
            if ($zbStatus !== null) {
                $createData['zerobounce_status'] = $zbStatus;
                $createData['zerobounce_checked_at'] = now();
            }
            AudienceUser::create($createData);
            $addedCount++;
        }

        $bodyParts = [
            __('Added: :count', ['count' => $addedCount]),
            __('Updated: :count', ['count' => $updatedCount]),
        ];
        if ($skippedInvalid > 0) {
            $bodyParts[] = __('Skipped (invalid): :count', ['count' => $skippedInvalid]);
        }

        Notification::make()
            ->title(__('CSV Upload Complete'))
            ->body(implode("\n", $bodyParts))
            ->success()
            ->send();

        if (!empty($typeErrors)) {
            Notification::make()
                ->title(__('CSV Import Warnings'))
                ->body(implode("\n", array_slice($typeErrors, 0, 10)))
                ->warning()
                ->send();
        }
    }
}
