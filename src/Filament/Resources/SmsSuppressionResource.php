<?php

namespace JanDev\EmailSystem\Filament\Resources;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use JanDev\EmailSystem\Filament\Resources\SmsSuppressionResource\Pages;
use JanDev\EmailSystem\Models\SmsSuppression;
use JanDev\EmailSystem\Support\Sms\SmsPhone;

/**
 * The numbers that must never be texted again.
 *
 * Visible and editable on purpose. On a cold list the opt-out is the only control
 * a recipient has, so someone has to be able to check that a STOP actually
 * landed, and to add a number by hand when a complaint arrives by another route
 * (an e-mail, a phone call, the regulator).
 *
 * Deleting an entry means that person can be texted again. It exists for the
 * genuine mistake - a number typed wrong - and for nothing else.
 */
class SmsSuppressionResource extends Resource
{
    protected static ?string $model = SmsSuppression::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-phone-x-mark';

    public static function getNavigationLabel(): string
    {
        return __('SMS Opt-outs');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('System');
    }

    public static function getModelLabel(): string
    {
        return __('SMS Opt-out');
    }

    public static function getPluralModelLabel(): string
    {
        return __('SMS Opt-outs');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('phone')
                ->label(__('Phone'))
                ->required()
                ->tel()
                ->helperText(__('International format, e.g. +447700900123. Stored normalised so the same number cannot slip back in written differently.'))
                ->dehydrateStateUsing(fn (?string $state): ?string => SmsPhone::normalise($state))
                ->rules([
                    fn (): \Closure => function (string $attribute, $value, \Closure $fail): void {
                        if (SmsPhone::normalise($value) === null) {
                            $fail(__('Use international format, starting with +.'));
                        }
                    },
                ]),

            Select::make('reason')
                ->label(__('Reason'))
                ->options([
                    'stop' => __('Replied STOP'),
                    'complaint' => __('Complaint'),
                    'manual' => __('Added by hand'),
                    'invalid' => __('Invalid number'),
                ])
                ->default('manual')
                ->required(),

            TextInput::make('source')
                ->label(__('Source'))
                ->helperText(__('Where the opt-out came from, for when someone asks later.'))
                ->nullable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(50)
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('phone')
                    ->label(__('Phone'))
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'stop' => 'warning',
                        'complaint' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('source')
                    ->label(__('Source'))
                    ->default('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('Opted out'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('reason')
                    ->label(__('Reason'))
                    ->options([
                        'stop' => __('Replied STOP'),
                        'complaint' => __('Complaint'),
                        'manual' => __('Added by hand'),
                        'invalid' => __('Invalid number'),
                    ]),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription(__('Removing this entry means the number can be texted again. Only do this if it was recorded by mistake.')),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->requiresConfirmation(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSmsSuppressions::route('/'),
        ];
    }
}
