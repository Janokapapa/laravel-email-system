<?php

namespace JanDev\EmailSystem\Filament\Resources;

use JanDev\EmailSystem\Filament\Resources\CampaignResource\Pages;
use JanDev\EmailSystem\Models\Campaign;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;

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
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->recordUrl(fn (Campaign $record): string => $record->status === 'new'
                ? static::getUrl('edit', ['record' => $record])
                : static::getUrl('view', ['record' => $record])
            )
            ->columns([
                TextColumn::make('name')
                    ->label(__('Campaign Name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('sender_name')
                    ->label(__('Sender'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('emailTemplate.name')
                    ->label(__('Template'))
                    ->default('—')
                    ->limit(30),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'new'     => 'gray',
                        'sending' => 'warning',
                        'sent'    => 'success',
                        'partial' => 'info',
                        'failed'  => 'danger',
                        default   => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'new'     => __('New'),
                        'sending' => __('Sending...'),
                        'sent'    => __('Sent'),
                        'partial' => __('Partial'),
                        'failed'  => __('Failed'),
                        default   => ucfirst($state),
                    }),

                TextColumn::make('progress')
                    ->label(__('Progress'))
                    ->getStateUsing(function (Campaign $record): string {
                        if ($record->status === 'new') {
                            return '—';
                        }
                        $total = $record->total_recipients;
                        $sent  = $record->sent_count;
                        if ($total === 0) {
                            return '—';
                        }
                        $pct = $record->getProgressPercent();
                        return "{$sent} / {$total} ({$pct}%)";
                    }),

                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (Campaign $record): bool => $record->status !== 'new'),
                Action::make('duplicate')
                    ->label(__('Duplicate'))
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(__('Duplicate Campaign'))
                    ->modalDescription(__('A copy of this campaign will be created as a new unsent campaign.'))
                    ->action(function (Campaign $record) {
                        $clone = $record->replicate();
                        $clone->name = $record->name . ' (' . __('copy') . ')';
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
