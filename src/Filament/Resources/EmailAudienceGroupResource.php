<?php

namespace JanDev\EmailSystem\Filament\Resources;

use JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource\Pages;
use JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource\RelationManagers\AudienceUsersRelationManager;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class EmailAudienceGroupResource extends Resource
{
    protected static ?string $model = EmailAudienceGroup::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    public static function getNavigationLabel(): string
    {
        return __('Email Audiences');
    }

    public static function getNavigationGroup(): ?string
    {
        return config('email-system.filament.navigation_group', 'Marketing');
    }

    public static function getModelLabel(): string
    {
        return __('Email Audience');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Email Audiences');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('name')
                ->required()
                ->label(__('Group Name')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Group Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('active_users_count')
                    ->label(__('Active'))
                    ->getStateUsing(fn ($record) => $record->active_users_count),
                TextColumn::make('inactive_users_count')
                    ->label(__('Inactive'))
                    ->getStateUsing(fn ($record) => $record->inactive_users_count),
                TextColumn::make('sent_count')
                    ->label(__('Sent'))
                    ->getStateUsing(fn ($record) => $record->sent_users_count)
                    ->color('success'),
                TextColumn::make('bounced_count')
                    ->label(__('Bounced'))
                    ->getStateUsing(fn ($record) => $record->bounced_users_count)
                    ->color('danger'),
            ])
            ->filters([]);
    }

    public static function getRelations(): array
    {
        return [
            AudienceUsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailAudienceGroups::route('/'),
            'create' => Pages\CreateEmailAudienceGroup::route('/create'),
            'edit' => Pages\EditEmailAudienceGroup::route('/{record}/edit'),
        ];
    }
}
