<?php

namespace JanDev\EmailSystem\Filament\Resources;

use JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource\Pages;
use JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource\RelationManagers\AudienceUsersRelationManager;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Support\Sms\SmsPhone;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class EmailAudienceGroupResource extends Resource
{
    protected static ?string $model = EmailAudienceGroup::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    // A group is channel-agnostic: one email_audience_groups row feeds both an
    // e-mail campaign and an SMS one (SmsCampaignSender reads the same
    // email_audience_group_id). Calling it an "Email List" in the UI made the
    // SMS side look like it needed a list of its own.
    public static function getNavigationLabel(): string
    {
        return __('Lists');
    }

    public static function getNavigationGroup(): ?string
    {
        return config('email-system.filament.navigation_group', 'Marketing');
    }

    public static function getModelLabel(): string
    {
        return __('List');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Lists');
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
                // What the list can actually be used for. Counted from its
                // contents rather than a flag set at import: a flag goes stale
                // the first time someone adds rows of the other kind, and a
                // list that claims to be textable but is not is found out at
                // send time.
                'audienceUsers as email_count' => fn ($q) => $q->whereNotNull('email')->where('email', '!=', ''),
                'audienceUsers as sms_count' => fn ($q) => $q->whereNotNull('phone')
                    ->where('phone', 'REGEXP', SmsPhone::E164_SQL_REGEX),
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Group Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('list_type')
                    ->label(__('Contains'))
                    ->badge()
                    ->getStateUsing(function ($record): string {
                        $hasEmail = ($record->email_count ?? 0) > 0;
                        $hasSms = ($record->sms_count ?? 0) > 0;

                        return match (true) {
                            $hasEmail && $hasSms => __('Email + SMS'),
                            $hasSms => __('SMS'),
                            $hasEmail => __('Email'),
                            default => __('Empty'),
                        };
                    })
                    ->color(fn (string $state): string => match ($state) {
                        __('Email + SMS') => 'success',
                        __('SMS') => 'info',
                        __('Email') => 'warning',
                        default => 'gray',
                    })
                    ->description(fn ($record): string => trim(sprintf(
                        '%s %s · %s %s',
                        number_format($record->email_count ?? 0),
                        __('addresses'),
                        number_format($record->sms_count ?? 0),
                        __('numbers')
                    ))),
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
            ->filters([
                // Asked of a long list of lists: "which of these can I text?"
                \Filament\Tables\Filters\SelectFilter::make('contains')
                    ->label(__('Contains'))
                    ->options([
                        'sms' => __('Phone numbers'),
                        'email' => __('Email addresses'),
                        'both' => __('Both'),
                    ])
                    ->query(function ($query, array $data) {
                        $value = $data['value'] ?? null;
                        if (!$value) {
                            return $query;
                        }

                        $hasPhone = fn ($q) => $q->whereNotNull('phone')
                            ->where('phone', 'REGEXP', SmsPhone::E164_SQL_REGEX);
                        $hasEmail = fn ($q) => $q->whereNotNull('email')->where('email', '!=', '');

                        return match ($value) {
                            'sms' => $query->whereHas('audienceUsers', $hasPhone),
                            'email' => $query->whereHas('audienceUsers', $hasEmail),
                            'both' => $query->whereHas('audienceUsers', $hasPhone)
                                ->whereHas('audienceUsers', $hasEmail),
                            default => $query,
                        };
                    }),
            ]);
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
