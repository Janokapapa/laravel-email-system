<?php

namespace JanDev\EmailSystem\Filament\Resources;

use JanDev\EmailSystem\Filament\Resources\EmailTemplateResource\Pages;
use JanDev\EmailSystem\Models\EmailTemplate;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\AudienceUser;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
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

            Select::make('content_type')
                ->label(__('Content Type'))
                ->options([
                    'html' => __('HTML'),
                    'text' => __('Plain Text'),
                ])
                ->default('html')
                ->required()
                ->live(),

            TextInput::make('subject')
                ->required()
                ->label(__('Subject')),

            static::buildPlaceholderHint(),

            Field::make('body')
                ->label(__('Email Body'))
                ->view('email-system::forms.tinymce')
                ->extraAttributes(fn (Get $get) => [
                    'height' => 700,
                    'maxWidth' => 620,
                    'contentType' => $get('content_type') ?? 'html',
                ])
                ->columnSpanFull()
                ->reactive()
                ->dehydrated(true)
                ->dehydrateStateUsing(fn ($state) => $state),

            Section::make(__('Variations'))
                ->description(__('Add subject/body variations. The sender will randomly pick one per recipient.'))
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
                        ])
                        ->columns(1)
                        ->reorderable()
                        ->reorderableWithDragAndDrop()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => ($state['subject'] ?? '') !== '' ? __('Variation') . ': ' . $state['subject'] : __('New Variation'))
                        ->defaultItems(0)
                        ->addActionLabel(__('Add Variation'))
                        ->dehydrated(false),
                ]),
        ]);
    }

    protected static function buildPlaceholderHint(): Placeholder
    {
        return \JanDev\EmailSystem\Support\PlaceholderHint::make();
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
                ViewColumn::make('send_statistics')
                    ->label(__('Send Statistics'))
                    ->view('email-system::filament.tables.email-template-stats'),
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
                        'stats' => $record->getSendStatistics(),
                        'detailedStats' => EmailLog::where('email_template_id', $record->id)
                            ->selectRaw("
                                COUNT(*) as total,
                                SUM(CASE WHEN status IN ('sent','spooled') THEN 1 ELSE 0 END) as sent,
                                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                                SUM(clicked) as clicked_count,
                                SUM(complained) as complained_count,
                                SUM(CASE WHEN bounce_type = 'hard' THEN 1 ELSE 0 END) as hard_bounce,
                                SUM(CASE WHEN bounce_type = 'soft' THEN 1 ELSE 0 END) as soft_bounce
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
