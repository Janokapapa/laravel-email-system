<?php

namespace JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource\RelationManagers;

use JanDev\EmailSystem\Models\AudienceUser;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
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
    protected static string $relationship = 'audienceUsers';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
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
            ])
            ->headerActions([
                Action::make('downloadFilteredCsv')
                    ->label(__('Download CSV'))
                    ->action(function () {
                        $filteredQuery = $this->getFilteredTableQuery();

                        return response()->streamDownload(function () use ($filteredQuery) {
                            $handle = fopen('php://output', 'w');
                            fputcsv($handle, [__('address'), __('tags'), __('created_at')]);

                            foreach ($filteredQuery->get() as $user) {
                                fputcsv($handle, [
                                    $user->email,
                                    '',
                                    $user->created_at,
                                ]);
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
                    ->label(__('audience_groups.add_all_subscribed_to_group'))
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
                            ->title(__('audience_groups.success'))
                            ->body(trans_choice('audience_groups.added_count', $addedCount, ['count' => $addedCount]))
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation(__('audience_groups.add_confirmation'))
                    ->icon('heroicon-o-plus'),

                // Add users by registration date range - uses config callback
                Action::make('addUsersByDateRange')
                    ->label(__('audience_groups.add_users_by_date'))
                    ->visible(fn () => resolve_callback(config('email-system.add_users_by_date_callback')) !== null)
                    ->schema([
                        DatePicker::make('date_from')
                            ->label(__('audience_groups.date_from'))
                            ->required()
                            ->native(false)
                            ->displayFormat('Y-m-d'),
                        DatePicker::make('date_to')
                            ->label(__('audience_groups.date_to'))
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
                            ->title(__('audience_groups.success'))
                            ->body(__('audience_groups.added_with_skipped', ['added' => $addedCount, 'skipped' => $skippedCount]))
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation(__('audience_groups.add_by_date_confirmation'))
                    ->icon('heroicon-o-calendar-days'),

                // Upload CSV action
                Action::make('uploadCsv')
                    ->label(__('audience_groups.upload_csv'))
                    ->schema([
                        FileUpload::make('csv_file')
                            ->label(__('audience_groups.select_csv_file'))
                            ->acceptedFileTypes(['text/csv', 'text/plain', '.csv'])
                            ->disk('temp')
                            ->directory('')
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $groupId = $this->getOwnerRecord()->id;
                        $addedCount = 0;

                        $csvPath = Storage::disk('temp')->path($data['csv_file']);

                        if (!Storage::disk('temp')->exists($data['csv_file'])) {
                            Notification::make()
                                ->title(__('audience_groups.csv_invalid'))
                                ->body(__('audience_groups.file_missing'))
                                ->warning()
                                ->send();
                            return;
                        }

                        $csvData = array_map(function ($line) {
                            return str_getcsv($line, ';');
                        }, file($csvPath));
                        Storage::disk('temp')->delete($data['csv_file']);

                        $header = array_map('trim', $csvData[0]);
                        $expectedHeader = ['name', 'email'];

                        if ($header !== $expectedHeader) {
                            Notification::make()
                                ->title(__('audience_groups.csv_invalid'))
                                ->body(__('audience_groups.invalid_csv_format'))
                                ->warning()
                                ->send();
                            return;
                        }
                        unset($csvData[0]);

                        foreach ($csvData as $row) {
                            $validator = Validator::make(
                                ['name' => $row[0], 'email' => $row[1]],
                                ['name' => 'required|string', 'email' => 'required|email']
                            );

                            if ($validator->fails()) {
                                continue;
                            }

                            $isInactiveInOtherGroups = AudienceUser::where('email', $row[1])
                                ->where('is_active', false)
                                ->where('email_audience_group_id', '<>', $groupId)
                                ->exists();

                            if ($isInactiveInOtherGroups) {
                                continue;
                            }

                            $exists = AudienceUser::where('email', $row[1])->exists();

                            if (!$exists) {
                                AudienceUser::create([
                                    'name' => $row[0],
                                    'email' => $row[1],
                                    'is_active' => true,
                                    'email_audience_group_id' => $groupId,
                                ]);
                                $addedCount++;
                            }
                        }

                        Notification::make()
                            ->title(__('audience_groups.upload_success'))
                            ->body(trans_choice('audience_groups.added_count', $addedCount, ['count' => $addedCount]))
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation(__('audience_groups.upload_confirmation'))
                    ->icon('heroicon-o-arrow-up-tray'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
