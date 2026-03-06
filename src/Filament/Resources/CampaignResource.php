<?php

namespace JanDev\EmailSystem\Filament\Resources;

use JanDev\EmailSystem\Filament\Resources\CampaignResource\Pages;
use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\EmailLog;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    public static function getNavigationLabel(): string
    {
        return __('Campaigns');
    }

    public static function getNavigationGroup(): ?string
    {
        return config('email-system.filament.navigation_group', 'Marketing');
    }

    public static function getModelLabel(): string
    {
        return __('Campaign');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Campaigns');
    }

    public static function table(Table $table): Table
    {
        $tableBuilder = $table
            ->defaultPaginationPageOption(50)
            ->defaultSort('created_at', 'desc')
            ->poll(fn () => Campaign::where('status', 'sending')
                ->where('created_at', '>=', now()->subDay())
                ->exists() ? '5s' : null
            )
            ->recordUrl(fn (Campaign $record): string => $record->status === 'new'
                ? static::getUrl('edit', ['record' => $record])
                : static::getUrl('view', ['record' => $record])
            )
            ->columns([
                TextColumn::make('name')
                    ->label(__('Campaign'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->limit(30)
                    ->tooltip(fn (Campaign $record): ?string => strlen($record->name) > 30 ? $record->name : null),

                TextColumn::make('sender_name')
                    ->label(__('Sender'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('emailTemplate.name')
                    ->label(__('Template'))
                    ->default('—')
                    ->limit(20)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'new'       => 'gray',
                        'sending'   => 'warning',
                        'sent'      => 'success',
                        'partial'   => 'info',
                        'failed'    => 'danger',
                        'cancelled' => 'danger',
                        default     => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'new'       => __('New'),
                        'sending'   => __('Sending...'),
                        'sent'      => __('Sent'),
                        'partial'   => __('Partial'),
                        'failed'    => __('Failed'),
                        'cancelled' => __('Cancelled'),
                        default     => ucfirst($state),
                    }),

                TextColumn::make('progress')
                    ->label(__('Progress'))
                    ->getStateUsing(function (Campaign $record): string {
                        if ($record->total_recipients === 0) {
                            return '—';
                        }

                        // Live counts from email_logs for sending campaigns
                        if ($record->status === 'sending') {
                            $counts = EmailLog::where('campaign_id', $record->id)
                                ->selectRaw("
                                    SUM(CASE WHEN status IN ('sent', 'spooled') THEN 1 ELSE 0 END) as sent,
                                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                                ")
                                ->first();
                            $sent = (int) ($counts->sent ?? 0);
                            $failed = (int) ($counts->failed ?? 0);
                            $record->setAttribute('_live_sent', $sent);
                            $record->setAttribute('_live_failed', $failed);
                            return $sent . '/' . $record->total_recipients;
                        }

                        return $record->sent_count . '/' . $record->total_recipients;
                    })
                    ->formatStateUsing(function (string $state, Campaign $record): string {
                        if ($state === '—') {
                            return '—';
                        }

                        $total = $record->total_recipients;
                        $sent = $record->getAttribute('_live_sent') ?? $record->sent_count;
                        $failed = $record->getAttribute('_live_failed') ?? $record->failed_count;
                        $pct = $total > 0 ? (int) round(($sent / $total) * 100) : 0;

                        $barColor = $record->status === 'sending' ? '#f59e0b' : '#22c55e';
                        $failedInfo = $failed > 0 ? " <span style=\"color:#ef4444;font-size:11px\">({$failed} " . __('failed') . ")</span>" : '';

                        return '<div style="display:flex;flex-direction:column;gap:3px;width:140px">'
                            . '<span style="font-size:12px">' . $sent . ' / ' . $total . $failedInfo . '</span>'
                            . '<div style="width:100%;background:rgba(128,128,128,0.2);border-radius:9999px;height:6px">'
                            . '<div style="width:' . $pct . '%;background:' . $barColor . ';border-radius:9999px;height:6px;transition:width 0.3s"></div>'
                            . '</div></div>';
                    })
                    ->html(),

                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime('m-d H:i')
                    ->sortable(),
            ])
            ->filters([])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (Campaign $record): bool => $record->status !== 'new'),
                Action::make('stop')
                    ->label(__('Stop'))
                    ->icon('heroicon-o-stop-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Stop Sending'))
                    ->modalDescription(__('All remaining queued emails for this campaign will be cancelled. Already sent emails are not affected.'))
                    ->visible(fn (Campaign $record): bool => $record->status === 'sending')
                    ->action(function (Campaign $record) {
                        $cancelled = EmailLog::where('campaign_id', $record->id)
                            ->where('status', 'queued')
                            ->update(['status' => 'cancelled', 'error' => 'Campaign stopped by user']);

                        $record->refreshCounts();
                        $record->status = $record->sent_count > 0 ? 'cancelled' : 'failed';
                        $record->save();

                        Notification::make()
                            ->title(__('Campaign stopped'))
                            ->body(__(':count emails cancelled', ['count' => $cancelled]))
                            ->success()
                            ->send();
                    }),
                Action::make('duplicate')
                    ->label(__('Duplicate'))
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(__('Duplicate Campaign'))
                    ->modalDescription(__('A copy of this campaign will be created as a new unsent campaign.'))
                    ->action(function (Campaign $record) {
                        $clone = $record->replicate();
                        $baseName = preg_replace('/\s*\(\d+\)$/', '', $record->name);
                        $existing = Campaign::where('name', 'LIKE', $baseName . ' (%)')
                            ->where('name', 'REGEXP', '^' . preg_quote($baseName, '/') . ' \\([0-9]+\\)$')
                            ->pluck('name');
                        $maxNum = 0;
                        foreach ($existing as $name) {
                            if (preg_match('/\((\d+)\)$/', $name, $m)) {
                                $maxNum = max($maxNum, (int) $m[1]);
                            }
                        }
                        $clone->name = $baseName . ' (' . ($maxNum + 1) . ')';
                        $clone->status = 'new';
                        $clone->current_step = 5;
                        $clone->total_recipients = 0;
                        $clone->sent_count = 0;
                        $clone->failed_count = 0;
                        $clone->sent_at = null;
                        $clone->save();

                        return redirect(static::getUrl('edit', ['record' => $clone]));
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);

        return $tableBuilder;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'edit'   => Pages\EditCampaign::route('/{record}/edit'),
            'view'   => Pages\ViewCampaign::route('/{record}'),
        ];
    }
}
