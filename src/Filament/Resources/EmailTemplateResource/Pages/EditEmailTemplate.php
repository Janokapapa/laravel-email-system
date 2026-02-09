<?php

namespace JanDev\EmailSystem\Filament\Resources\EmailTemplateResource\Pages;

use JanDev\EmailSystem\Filament\Resources\EmailTemplateResource;
use JanDev\EmailSystem\Jobs\QueueEmailsForAudience;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Models\EmailLog;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

use function JanDev\EmailSystem\resolve_callback;

class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    // Pending send data for confirmation step
    public ?int $pendingAudienceGroupId = null;
    public ?bool $pendingSkipYahoo = null;
    public ?int $pendingNewCount = null;
    public ?int $pendingAlreadySentCount = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            $this->sendTestEmailAction(),
            $this->sendMailAction(),
            $this->confirmSendAction(),
        ];
    }

    protected function sendTestEmailAction(): Action
    {
        return Action::make('sendTestEmail')
            ->label(__('Send Test Email'))
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->form([
                TextInput::make('test_email')
                    ->label(__('Email Address'))
                    ->email()
                    ->required()
                    ->default(auth()->user()->email),
            ])
            ->action(function (array $data) {
                EmailLog::create([
                    'recipient' => $data['test_email'],
                    'subject' => '[TEST] ' . $this->record->subject,
                    'message' => $this->record->body,
                    'sender' => config('email-system.from.address'),
                    'status' => 'queued',
                ]);

                Notification::make()
                    ->title(__('Test email queued'))
                    ->body(__('Test email will be sent to :email', ['email' => $data['test_email']]))
                    ->success()
                    ->send();
            });
    }

    protected function sendMailAction(): Action
    {
        // Get groups that already received this template
        $sentGroupIds = EmailLog::where('email_template_id', $this->record->id)
            ->whereIn('status', ['sent', 'queued'])
            ->distinct()
            ->pluck('email_audience_group_id')
            ->filter()
            ->toArray();

        return Action::make('sendEmail')
            ->label(__('email_template.send_mail_to_audience'))
            ->form([
                Select::make('audienceGroupId')
                    ->label(__('email_template.select_audience_group'))
                    ->options(function () use ($sentGroupIds) {
                        return EmailAudienceGroup::orderBy('name')
                            ->get()
                            ->mapWithKeys(function ($group) use ($sentGroupIds) {
                                $label = $group->name;
                                if (in_array($group->id, $sentGroupIds)) {
                                    $count = EmailLog::where('email_template_id', $this->record->id)
                                        ->where('email_audience_group_id', $group->id)
                                        ->whereIn('status', ['sent', 'queued'])
                                        ->count();
                                    $label = "✓ {$group->name} ({$count} " . __('sent') . ")";
                                }
                                return [$group->id => $label];
                            })
                            ->toArray();
                    })
                    ->required()
                    ->searchable(),
                Checkbox::make('skipYahoo')
                    ->label(__('email_template.skip_yahoo'))
                    ->helperText(__('email_template.skip_yahoo_help'))
                    ->default(true),
            ])
            ->action(function (array $data) {
                $audienceGroup = EmailAudienceGroup::findOrFail($data['audienceGroupId']);
                $skipYahoo = $data['skipYahoo'] ?? false;

                // Calculate new recipients count
                $alreadySentEmails = EmailLog::where('email_template_id', $this->record->id)
                    ->whereIn('status', ['sent', 'queued'])
                    ->pluck('recipient');

                $query = $audienceGroup->audienceUsers()
                    ->where('is_active', true)
                    ->where('bounced', false)
                    ->whereNotIn('email', $alreadySentEmails);

                // Exclude blocked (bounced/inactive in other groups)
                $blockedEmails = AudienceUser::where(function ($q) {
                        $q->where('is_active', false)->orWhere('bounced', true);
                    })->pluck('email');

                // Get additional blocked from config
                $additionalBlocked = collect();
                $blockedCallback = resolve_callback(config('email-system.blocked_emails_callback'));
                if ($blockedCallback) {
                    $additionalBlocked = collect($blockedCallback());
                }

                $excludeEmails = $blockedEmails->merge($additionalBlocked)->unique();

                if ($excludeEmails->isNotEmpty()) {
                    $query->whereNotIn('email', $excludeEmails);
                }

                if ($skipYahoo) {
                    $query->where('email', 'not regexp', '@(yahoo|ymail)\\.');
                }

                $newCount = $query->count();
                $alreadySentCount = $alreadySentEmails->count();

                if ($newCount === 0) {
                    Notification::make()
                        ->title(__('No new recipients'))
                        ->body(__('All :count recipients already received this newsletter.', [
                            'count' => number_format($alreadySentCount),
                        ]))
                        ->warning()
                        ->send();
                    return;
                }

                // Store data and open confirmation action
                $this->pendingAudienceGroupId = $audienceGroup->id;
                $this->pendingSkipYahoo = $skipYahoo;
                $this->pendingNewCount = $newCount;
                $this->pendingAlreadySentCount = $alreadySentCount;

                $this->mountAction('confirmSend');
            });
    }

    protected function confirmSendAction(): Action
    {
        return Action::make('confirmSend')
            ->hidden()
            ->modalHeading(__('Confirm sending'))
            ->modalDescription(function () {
                $lines = [];
                $lines[] = __('New recipients: :count', ['count' => number_format($this->pendingNewCount ?? 0)]);
                if ($this->pendingAlreadySentCount > 0) {
                    $lines[] = __('Already sent (skipped): :count', ['count' => number_format($this->pendingAlreadySentCount)]);
                }
                return implode("\n", $lines);
            })
            ->modalSubmitActionLabel(__('Send'))
            ->requiresConfirmation()
            ->action(function () {
                if (!$this->pendingAudienceGroupId || !$this->pendingNewCount) {
                    return;
                }

                QueueEmailsForAudience::dispatch(
                    $this->record->id,
                    $this->pendingAudienceGroupId,
                    $this->pendingSkipYahoo ?? false,
                    auth()->id()
                );

                Notification::make()
                    ->title(__('Queueing started'))
                    ->body(__('Queueing :count emails in the background. You will be notified when done.', [
                        'count' => number_format($this->pendingNewCount),
                    ]))
                    ->info()
                    ->send();

                // Clear pending data
                $this->pendingAudienceGroupId = null;
                $this->pendingSkipYahoo = null;
                $this->pendingNewCount = null;
                $this->pendingAlreadySentCount = null;
            });
    }
}
