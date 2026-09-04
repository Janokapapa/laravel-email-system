<?php

namespace JanDev\EmailSystem\Filament\Resources;

use JanDev\EmailSystem\Filament\Resources\EmailLogResource\Pages;
use JanDev\EmailSystem\Models\EmailLog;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Support\HtmlString;

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
            ->defaultPaginationPageOption(50)
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
                TextColumn::make('sender_name')
                    ->label(__('Sender'))
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->searchable()
                    ->placeholder(__('—')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'delivered' => 'success',
                        'queued' => 'warning',
                        'spooled' => 'info',
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
                        'spooled' => __('Spooled (PMTA)'),
                        'sent' => __('Sent'),
                        'delivered' => __('Delivered'),
                        'failed' => __('Failed'),
                    ]),
                SelectFilter::make('sender_name')
                    ->label(__('Sender'))
                    ->options(fn () => EmailLog::query()
                        ->whereNotNull('sender_name')
                        ->distinct()
                        ->pluck('sender_name', 'sender_name')
                        ->toArray()),
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
            Section::make(__('Details'))
                ->columns(2)
                ->schema([
                    TextInput::make('recipient')
                        ->label(__('Recipient'))
                        ->disabled(),
                    TextInput::make('subject')
                        ->label(__('Subject'))
                        ->disabled(),
                    TextInput::make('sender_name')
                        ->label(__('Sender'))
                        ->disabled(),
                    TextInput::make('status')
                        ->label(__('Status'))
                        ->disabled(),
                    TextInput::make('sender')
                        ->label(__('From Address'))
                        ->disabled(),
                    TextInput::make('reply_to')
                        ->label(__('Reply-To'))
                        ->disabled(),
                    TextInput::make('content_type')
                        ->label(__('Content Type'))
                        ->disabled(),
                    TextInput::make('created_at')
                        ->label(__('Created At'))
                        ->disabled(),
                    Textarea::make('error')
                        ->label(__('Error'))
                        ->disabled()
                        ->columnSpanFull()
                        ->visible(fn ($record) => $record && $record->error),
                ]),

            Section::make(__('Tracking'))
                ->columns(3)
                ->collapsed()
                ->schema([
                    TextInput::make('opened_at')
                        ->label(__('Opened'))
                        ->disabled(),
                    TextInput::make('clicked_at')
                        ->label(__('Clicked'))
                        ->disabled(),
                    TextInput::make('unsubscribed_at')
                        ->label(__('Unsubscribed'))
                        ->disabled(),
                    TextInput::make('bounce_type')
                        ->label(__('Bounce Type'))
                        ->disabled(),
                    TextInput::make('bounce_reason')
                        ->label(__('Bounce Reason'))
                        ->disabled()
                        ->columnSpan(2),
                ]),

            Section::make(__('Email Content'))
                ->schema([
                    Placeholder::make('message_preview')
                        ->label('')
                        ->content(function ($record): HtmlString {
                            if (!$record) {
                                return new HtmlString('<p class="text-gray-400">' . __('No content') . '</p>');
                            }

                            // An old log whose body was dropped by
                            // email-system:compact-logs. Saying so beats an
                            // empty frame that looks like a bug.
                            if (!$record->message && $record->compacted_at) {
                                return new HtmlString(
                                    '<p class="text-gray-400">'
                                    . e(__('The content of this message was archived on :date. Statistics are kept.', [
                                        'date' => $record->compacted_at->format('Y-m-d'),
                                    ]))
                                    . '</p>'
                                );
                            }

                            if (!$record->message) {
                                return new HtmlString('<p class="text-gray-400">' . __('No content') . '</p>');
                            }

                            $isHtml = ($record->content_type ?? 'html') !== 'text';

                            if (!$isHtml) {
                                return new HtmlString(
                                    '<pre style="white-space:pre-wrap;font-family:monospace;font-size:14px;line-height:1.6;padding:1rem;background:#f8f9fa;border-radius:8px;max-width:100%;overflow-x:auto;">'
                                    . e($record->message)
                                    . '</pre>'
                                );
                            }

                            $html = $record->message;
                            if (stripos($html, '<html') === false) {
                                $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;">' . $html . '</body></html>';
                            }

                            $uid = 'ep-' . $record->id;

                            return new HtmlString(
                                '<iframe id="' . $uid . '" '
                                . 'srcdoc="' . e($html) . '" '
                                . 'style="width:100%;min-height:600px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;" '
                                . 'sandbox="allow-same-origin"'
                                . '></iframe>'
                                . '<script>!function(){var f=document.getElementById("' . $uid . '");'
                                . 'f.addEventListener("load",function(){try{var h=f.contentDocument.documentElement.scrollHeight;'
                                . 'f.style.height=Math.max(h+20,400)+"px";}catch(e){}});}();</script>'
                            );
                        })
                        ->columnSpanFull(),
                ]),
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
