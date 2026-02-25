<?php

namespace JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource\RelationManagers;

use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Support\CustomFieldComponents;
use JanDev\EmailSystem\Support\CsvHelper;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

use function JanDev\EmailSystem\resolve_callback;

class AudienceUsersRelationManager extends RelationManager
{
    /** @var array<int, string> CSV column headers detected from uploaded file */
    public array $csvColumnOptions = [];
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

                Toggle::make('is_active')
                    ->label(__('Active Status'))
                    ->inline(false),

                ...CustomFieldComponents::formFields(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('User Name')),
                TextColumn::make('email')->label(__('Email')),
                TextColumn::make('created_at')->label(__('Added At'))->dateTime('Y-m-d H:i:s'),

                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean()
                    ->trueIcon('heroicon-s-check-circle')
                    ->falseIcon('heroicon-s-x-circle'),

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
                        }, __('filtered_audience_users.csv'), [
                            'Content-Type' => 'text/csv',
                            'Content-Disposition' => 'attachment; filename="filtered_audience_users.csv"',
                        ]);
                    })
                    ->icon('heroicon-o-arrow-down-tray')
                    ->requiresConfirmation(__('Do you want to download the filtered audience users as CSV?')),

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
                                ->body(__('This user is already in the audience group.'))
                                ->warning()
                                ->send();
                            return;
                        }

                        AudienceUser::create([
                            'name' => $data['name'],
                            'email' => $data['email'],
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
                            $isInactiveInOtherGroups = AudienceUser::where('email', $user->email)
                                ->where('is_active', false)
                                ->where('email_audience_group_id', '<>', $groupId)
                                ->exists();

                            if ($isInactiveInOtherGroups) {
                                continue;
                            }

                            $exists = AudienceUser::where('email', $user->email)->exists();

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
                            $isInactiveInOtherGroups = AudienceUser::where('email', $user->email)
                                ->where('is_active', false)
                                ->where('email_audience_group_id', '<>', $groupId)
                                ->exists();

                            if ($isInactiveInOtherGroups) {
                                $skippedCount++;
                                continue;
                            }

                            $exists = AudienceUser::where('email', $user->email)->exists();

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
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
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
                ->acceptedFileTypes(['text/csv', 'text/plain', '.csv'])
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

                    // Store options in Livewire property so Select closures can read them
                    $options = ['' => __('-- Skip --')];
                    foreach ($detected['headers'] as $i => $header) {
                        $options[(string) $i] = $header;
                    }
                    $this->csvColumnOptions = $options;

                    // Auto-detect and pre-fill mapping selects
                    $headers = $detected['headers'];
                    $nameIdx = CsvHelper::autoDetectColumn($headers, ['name', 'név', 'nev', 'username']);
                    $emailIdx = CsvHelper::autoDetectColumn($headers, ['email', 'e-mail', 'emailcim', 'mail']);
                    if ($nameIdx !== null) {
                        $set('map_name', $nameIdx);
                    }
                    if ($emailIdx !== null) {
                        $set('map_email', $emailIdx);
                    }

                    foreach (AudienceUser::getCustomFieldDefinitions() as $def) {
                        $slug = $def['slug'] ?? null;
                        if (!$slug) {
                            continue;
                        }
                        $idx = CsvHelper::autoDetectColumn($headers, [$slug]);
                        if ($idx !== null) {
                            $set('map_cf_' . $slug, $idx);
                        }
                    }
                }),

            Select::make('map_name')
                ->label(__('Name'))
                ->options(fn (): array => $this->csvColumnOptions)
                ->visible(fn (): bool => !empty($this->csvColumnOptions))
                ->required(fn (): bool => !empty($this->csvColumnOptions)),

            Select::make('map_email')
                ->label(__('Email'))
                ->options(fn (): array => $this->csvColumnOptions)
                ->visible(fn (): bool => !empty($this->csvColumnOptions))
                ->required(fn (): bool => !empty($this->csvColumnOptions)),
        ];

        foreach (AudienceUser::getCustomFieldDefinitions() as $def) {
            $slug = $def['slug'] ?? null;
            if (!$slug) {
                continue;
            }
            $fields[] = Select::make('map_cf_' . $slug)
                ->label($def['name'] ?? $slug)
                ->options(fn (): array => $this->csvColumnOptions)
                ->visible(fn (): bool => !empty($this->csvColumnOptions));
        }

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

        // Read file and detect separator
        $lines = file($csvPath);
        Storage::disk('local')->delete($data['csv_file']);

        if (empty($lines)) {
            Notification::make()->title(__('Error'))->body(__('The CSV file is empty.'))->warning()->send();
            return;
        }

        $headerLine = trim(preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]));
        $semicolons = substr_count($headerLine, ';');
        $commas = substr_count($headerLine, ',');
        $separator = $semicolons >= $commas ? ';' : ',';

        array_shift($lines); // skip header

        $addedCount = 0;
        $skippedCount = 0;
        $typeErrors = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $row = str_getcsv($line, $separator);
            $name = trim($row[$nameIdx] ?? '');
            $email = trim($row[$emailIdx] ?? '');

            $validator = Validator::make(
                ['name' => $name, 'email' => $email],
                ['name' => 'required|string', 'email' => 'required|email'],
            );

            if ($validator->fails()) {
                $skippedCount++;
                continue;
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

            $isInactiveInOtherGroups = AudienceUser::where('email', $email)
                ->where('is_active', false)
                ->where('email_audience_group_id', '<>', $groupId)
                ->exists();

            if ($isInactiveInOtherGroups) {
                $skippedCount++;
                continue;
            }

            if (AudienceUser::where('email', $email)->exists()) {
                $skippedCount++;
                continue;
            }

            AudienceUser::create([
                'name' => $name,
                'email' => $email,
                'is_active' => true,
                'email_audience_group_id' => $groupId,
                'custom_fields' => $customFields,
            ]);
            $addedCount++;
        }

        Notification::make()
            ->title(__('CSV Upload Complete'))
            ->body(__('Added: :added, Skipped: :skipped', ['added' => $addedCount, 'skipped' => $skippedCount]))
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
