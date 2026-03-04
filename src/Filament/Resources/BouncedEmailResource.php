<?php

namespace JanDev\EmailSystem\Filament\Resources;

use JanDev\EmailSystem\Filament\Resources\BouncedEmailResource\Pages;
use JanDev\EmailSystem\Models\BouncedEmail;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

class BouncedEmailResource extends Resource
{
    protected static ?string $model = BouncedEmail::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    public static function getNavigationLabel(): string
    {
        return __('Bounced Emails');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('System');
    }

    public static function getModelLabel(): string
    {
        return __('Bounced Email');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Bounced Emails');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(50)
            ->defaultSort('bounced_at', 'desc')
            ->columns([
                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('bounce_type')
                    ->label(__('Type'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'hard' => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pmta' => 'info',
                        'mailgun' => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('bounce_reason')
                    ->label(__('Reason'))
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('bounced_at')
                    ->label(__('Bounced At'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('bounce_type')
                    ->label(__('Bounce Type'))
                    ->options([
                        'hard' => __('Hard Bounce'),
                    ]),
                SelectFilter::make('source')
                    ->label(__('Source'))
                    ->options(fn () => BouncedEmail::query()
                        ->distinct()
                        ->pluck('source', 'source')
                        ->toArray()),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('email')
                ->label(__('Email'))
                ->disabled(),
            TextInput::make('bounce_type')
                ->label(__('Bounce Type'))
                ->disabled(),
            TextInput::make('source')
                ->label(__('Source'))
                ->disabled(),
            Textarea::make('bounce_reason')
                ->label(__('Bounce Reason'))
                ->disabled(),
            TextInput::make('bounced_at')
                ->label(__('Bounced At'))
                ->disabled(),
        ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBouncedEmails::route('/'),
            'view' => Pages\ViewBouncedEmail::route('/{record}'),
        ];
    }
}
