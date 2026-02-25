<?php

namespace JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource\Pages;

use JanDev\EmailSystem\Filament\Resources\EmailAudienceGroupResource;
use JanDev\EmailSystem\Jobs\MergeAudiencesJob;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListEmailAudienceGroups extends ListRecords
{
    protected static string $resource = EmailAudienceGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            Action::make('search_email')
                ->label(__('Search email'))
                ->icon('heroicon-o-magnifying-glass')
                ->color('info')
                ->form([
                    TextInput::make('email')
                        ->label(__('Email address'))
                        ->email()
                        ->required()
                        ->placeholder('example@email.com')
                        ->autocomplete('new-password')
                        ->id('search_group_addr')
                        ->extraInputAttributes([
                            'data-1p-ignore' => 'true',
                            'data-lpignore' => 'true',
                            'autocomplete' => 'new-password',
                        ]),
                ])
                ->action(function (array $data) {
                    $email = strtolower(trim($data['email']));

                    $audienceUsers = AudienceUser::where('email', $email)
                        ->with('emailAudienceGroup')
                        ->get();

                    if ($audienceUsers->isEmpty()) {
                        Notification::make()
                            ->title(__('Email not found'))
                            ->body(__('The email :email is not in any audience.', ['email' => $email]))
                            ->warning()
                            ->send();
                        return;
                    }

                    $groups = $audienceUsers->map(function ($user) {
                        $status = [];
                        if ($user->is_active) $status[] = __('Active');
                        if (!$user->is_active) $status[] = __('Inactive');
                        if ($user->sent_at) $status[] = __('Sent') . ': ' . $user->sent_at->format('Y-m-d');
                        if ($user->bounced) $status[] = __('Bounced');

                        return $user->emailAudienceGroup->name . ' (' . implode(', ', $status) . ')';
                    })->join("\n");

                    Notification::make()
                        ->title(__('Found in :count audience(s)', ['count' => $audienceUsers->count()]))
                        ->body($groups)
                        ->success()
                        ->persistent()
                        ->send();
                })
                ->modalHeading(__('Search email in audiences'))
                ->modalSubmitActionLabel(__('Search')),

            Action::make('merge')
                ->label(__('Merge audiences'))
                ->icon('heroicon-o-arrows-pointing-in')
                ->color('warning')
                ->form([
                    CheckboxList::make('source_ids')
                        ->label(__('Select audiences to merge (source)'))
                        ->options(EmailAudienceGroup::orderBy('name')->pluck('name', 'id'))
                        ->required()
                        ->columns(2)
                        ->searchable()
                        ->live(),

                    Select::make('target_id')
                        ->label(__('Merge into (target)'))
                        ->options(fn ($get) => EmailAudienceGroup::orderBy('name')
                            ->when($get('source_ids'), fn ($query, $sourceIds) =>
                                $query->whereNotIn('id', $sourceIds)
                            )
                            ->pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->helperText(__('Users from selected sources will be moved here. Duplicates will be skipped.')),

                    Select::make('delete_sources')
                        ->label(__('After merge'))
                        ->options([
                            'keep' => __('Keep source audiences'),
                            'delete' => __('Delete empty source audiences'),
                        ])
                        ->default('keep')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $sourceIds = $data['source_ids'];
                    $targetId = $data['target_id'];
                    $deleteSources = $data['delete_sources'] === 'delete';

                    $sourceIds = array_filter($sourceIds, fn($id) => $id != $targetId);

                    if (empty($sourceIds)) {
                        Notification::make()
                            ->title(__('No valid sources selected'))
                            ->warning()
                            ->send();
                        return;
                    }

                    $totalUsers = AudienceUser::whereIn('email_audience_group_id', $sourceIds)->count();

                    MergeAudiencesJob::dispatch(
                        array_values($sourceIds),
                        (int) $targetId,
                        $deleteSources,
                        auth()->id()
                    );

                    Notification::make()
                        ->title(__('Merge started'))
                        ->body(__('Merging :count users in the background. You will be notified when done.', [
                            'count' => number_format($totalUsers),
                        ]))
                        ->info()
                        ->send();
                })
                ->modalHeading(__('Merge audiences'))
                ->modalSubmitActionLabel(__('Merge'))
                ->requiresConfirmation(),
        ];
    }
}
