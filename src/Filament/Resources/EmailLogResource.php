<?php

namespace JanDev\EmailSystem\Filament\Resources;

use JanDev\EmailSystem\Filament\Resources\EmailLogResource\Pages;
use JanDev\EmailSystem\Models\EmailLog;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;

class EmailLogResource extends Resource
{
    protected static ?string $model = EmailLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationLabel(): string
    {
        return __('Email Logs');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('System');
    }

    public static function getModelLabel(): string
    {
        return __('Email Log');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Email Logs');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('recipient')
                    ->label(__('Recipient'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->limit(40)
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'queued' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('error')
                    ->label(__('Error'))
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options([
                        'queued' => __('Queued'),
                        'sent' => __('Sent'),
                        'failed' => __('Failed'),
                    ]),
                SelectFilter::make('email_template_id')
                    ->label(__('Template'))
                    ->relationship('emailTemplate', 'name'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->visible(fn ($record) => $record->status === 'queued'),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->action(function ($records) {
                        $queuedRecords = $records->filter(fn ($record) => $record->status === 'queued');
                        $queuedRecords->each->delete();
                    }),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('recipient')
                ->label(__('Recipient'))
                ->disabled(),
            TextInput::make('subject')
                ->label(__('Subject'))
                ->disabled(),
            Textarea::make('message')
                ->label(__('Message'))
                ->disabled(),
            TextInput::make('status')
                ->label(__('Status'))
                ->disabled(),
            Textarea::make('error')
                ->label(__('Error'))
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
            'index' => Pages\ListEmailLogs::route('/'),
            'view' => Pages\ViewEmailLog::route('/{record}'),
        ];
    }
}
