<?php

namespace JanDev\EmailSystem\Filament\Resources;

use JanDev\EmailSystem\Filament\Resources\AudienceUserResource\Pages;
use JanDev\EmailSystem\Models\AudienceUser;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteBulkAction;

class AudienceUserResource extends Resource
{
    protected static ?string $model = AudienceUser::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public static function getNavigationLabel(): string
    {
        return __('Audience Users');
    }

    public static function getNavigationGroup(): ?string
    {
        return config('email-system.filament.navigation_group', 'Marketing');
    }

    public static function getModelLabel(): string
    {
        return __('Audience Users');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Audience Users');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required(),

                TextInput::make('email')
                    ->label(__('Email'))
                    ->email()
                    ->required()
                    ->autocomplete('new-password')
                    ->id('audience_contact_addr')
                    ->extraInputAttributes([
                        'data-1p-ignore' => 'true',
                        'data-lpignore' => 'true',
                        'data-form-type' => 'other',
                        'autocomplete' => 'new-password',
                    ]),

                Select::make('email_audience_group_id')
                    ->label(__('Audience Group'))
                    ->relationship('emailAudienceGroup', 'name')
                    ->required(),

                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('User Name')),
                TextColumn::make('email')->label(__('Email')),
                TextColumn::make('emailAudienceGroup.name')->label(__('Audience Group')),
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
                            ->id('filter_audience_addr')
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
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAudienceUsers::route('/'),
            'create' => Pages\CreateAudienceUser::route('/create'),
            'edit' => Pages\EditAudienceUser::route('/{record}/edit'),
        ];
    }
}
