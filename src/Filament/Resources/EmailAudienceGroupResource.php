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
        return __('Email Lists');
    }

    public static function getNavigationGroup(): ?string
    {
        return config('email-system.filament.navigation_group', 'Marketing');
    }

    public static function getModelLabel(): string
    {
        return __('Email List');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Email Lists');
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
            ->defaultPaginationPageOption(50)
            ->modifyQueryUsing(fn ($query) => $query->withCount([
                'audienceUsers as total_count',
                'audienceUsers as active_count' => fn ($q) => $q->where('is_active', true),
                'audienceUsers as bounced_count' => fn ($q) => $q->where('bounced', true),
                'audienceUsers as zb_valid_count' => fn ($q) => $q->where('zerobounce_status', 'valid'),
                'audienceUsers as zb_invalid_count' => fn ($q) => $q->where('zerobounce_status', 'invalid'),
                'audienceUsers as zb_unverified_count' => fn ($q) => $q->where(function ($q2) {
                    $q2->whereNull('zerobounce_status')->orWhere('zerobounce_status', 'unverified');
                }),
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Group Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('total_count')
                    ->label(__('Total'))
                    ->getStateUsing(fn ($record) => $record->total_count),
                TextColumn::make('active_count')
                    ->label(__('Active'))
                    ->getStateUsing(fn ($record) => $record->active_count)
                    ->color(fn ($state) => $state > 0 ? 'success' : null),
                TextColumn::make('bounced_count')
                    ->label(__('Bounced'))
                    ->getStateUsing(fn ($record) => $record->bounced_count)
                    ->color(fn ($state) => $state > 0 ? 'danger' : null),
                TextColumn::make('zb_valid_count')
                    ->label(__('ZB Valid'))
                    ->getStateUsing(fn ($record) => $record->zb_valid_count)
                    ->color(fn ($state) => $state > 0 ? 'success' : null),
                TextColumn::make('zb_invalid_count')
                    ->label(__('ZB Invalid'))
                    ->getStateUsing(fn ($record) => $record->zb_invalid_count)
                    ->color(fn ($state) => $state > 0 ? 'danger' : null),
                TextColumn::make('zb_unverified_count')
                    ->label(__('Unverified'))
                    ->getStateUsing(fn ($record) => $record->zb_unverified_count)
                    ->color(fn ($state) => $state > 0 ? 'warning' : null),
                ...static::getExtraColumns(),
            ])
            ->filters([]);
    }

    /**
     * Returns extra table columns from the host app's hook.
     * Configured via email-system.filament.audience_group_extra_columns (invokable class).
     */
    protected static function getExtraColumns(): array
    {
        $class = config('email-system.filament.audience_group_extra_columns');
        if (!$class || !class_exists($class)) {
            return [];
        }

        try {
            return (array) app($class)();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('EmailAudienceGroupResource extra columns hook failed: ' . $e->getMessage());
            return [];
        }
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
