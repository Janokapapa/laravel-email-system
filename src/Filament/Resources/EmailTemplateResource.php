<?php

namespace JanDev\EmailSystem\Filament\Resources;

use JanDev\EmailSystem\Filament\Resources\EmailTemplateResource\Pages;
use JanDev\EmailSystem\Models\EmailTemplate;
use JanDev\EmailSystem\Models\EmailLog;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\RichEditor;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;

class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    public static function getNavigationLabel(): string
    {
        return __('Email Templates');
    }

    public static function getNavigationGroup(): ?string
    {
        return config('email-system.filament.navigation_group', 'Marketing');
    }

    public static function getModelLabel(): string
    {
        return __('Email Template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Email Templates');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')
                ->required()
                ->label(__('Template Name')),

            TextInput::make('subject')
                ->required()
                ->label(__('Subject')),

            RichEditor::make('body')
                ->required()
                ->label(__('Email Body'))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Template Name'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('subject')
                    ->label(__('Subject'))
                    ->sortable()
                    ->limit(40),
                TextColumn::make('emails_sent')
                    ->label(__('Sent'))
                    ->getStateUsing(fn ($record) => EmailLog::where('email_template_id', $record->id)->where('status', 'sent')->count())
                    ->color('success'),
                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
            ])
            ->filters([])
            ->actions([
                Action::make('statistics')
                    ->label(__('Statistics'))
                    ->icon('heroicon-m-chart-bar')
                    ->color('info')
                    ->modalHeading(fn ($record) => __('Send Statistics') . ': ' . $record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn ($record) => view('email-system::filament.email-template-stats', [
                        'record' => $record,
                        'stats' => EmailLog::where('email_template_id', $record->id)
                            ->selectRaw("
                                COUNT(*) as total,
                                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                                SUM(opened) as opened_count,
                                SUM(clicked) as clicked_count,
                                SUM(complained) as complained_count
                            ")
                            ->first(),
                    ])),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailTemplates::route('/'),
            'create' => Pages\CreateEmailTemplate::route('/create'),
            'edit' => Pages\EditEmailTemplate::route('/{record}/edit'),
        ];
    }
}
