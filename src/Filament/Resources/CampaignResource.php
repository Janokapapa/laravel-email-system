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
                    ->getStateUsing(fn (Campaign $record): string => $record->status === 'new' || $record->total_recipients === 0
                        ? '—'
                        : $record->sent_count . '/' . $record->total_recipients
                    )
                    ->formatStateUsing(function (string $state, Campaign $record): string {
                        if ($state === '—') {
                            return '—';
                        }
                        $sent = $record->sent_count;
                        $total = $record->total_recipients;
                        $pct = $record->getProgressPercent();
                        return '<div class="flex flex-col gap-1">'
                            . '<div class="text-xs">' . $sent . ' / ' . $total . '</div>'
                            . '<div class="w-full bg-gray-200 dark:bg-white/10 rounded-full h-2">'
                            . '<div class="bg-primary-500 h-2 rounded-full" style="width: ' . $pct . '%"></div>'
                            . '</div></div>';
                    })
                    ->html(),

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
