<?php

namespace JanDev\EmailSystem\Filament\Resources\CampaignResource\Pages;

use JanDev\EmailSystem\Filament\Resources\CampaignResource;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Models\EmailLog;
use Filament\Resources\Pages\Page;

class ViewCampaign extends Page
{
    protected static string $resource = CampaignResource::class;

    protected string $view = 'email-system::filament.pages.view-campaign';

    public $record;

    public function mount(int|string $record): void
    {
        $this->record = \JanDev\EmailSystem\Models\Campaign::findOrFail($record);
    }

    public function getTitle(): string
    {
        return $this->record->name ?? __('Campaign');
    }

    public function getStats(): object
    {
        return EmailLog::where('campaign_id', $this->record->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('sent','spooled') THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                SUM(clicked) as clicked_count,
                SUM(complained) as complained_count,
                SUM(CASE WHEN bounce_type = 'hard' THEN 1 ELSE 0 END) as hard_bounce,
                SUM(CASE WHEN bounce_type = 'soft' THEN 1 ELSE 0 END) as soft_bounce,
                SUM(unsubscribed) as unsubscribed_count
            ")
            ->first();
    }

    public function getAudienceStats(): \Illuminate\Support\Collection
    {
        return EmailLog::where('campaign_id', $this->record->id)
            ->whereNotNull('email_audience_group_id')
            ->selectRaw("
                email_audience_group_id,
                COUNT(*) as total_sent,
                SUM(clicked) as clicked_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(unsubscribed) as unsubscribed_count,
                MAX(created_at) as last_sent
            ")
            ->groupBy('email_audience_group_id')
            ->get();
    }

    public function getListNames(): string
    {
        $groupIds = $this->record->audience_group_ids ?? [];
        if (empty($groupIds)) return '—';
        $groups = EmailAudienceGroup::whereIn('id', $groupIds)->pluck('name', 'id');
        return collect($groupIds)->map(fn ($id) => $groups[$id] ?? __('Deleted'))->join(', ') ?: '—';
    }

    public function getSkippedProviders(): string
    {
        $providers = $this->record->skip_providers ?? [];
        if (empty($providers)) return '';
        $labels = ['yahoo' => 'Yahoo', 'microsoft' => 'Microsoft', 'gmail' => 'Gmail', 'icloud' => 'iCloud'];
        return collect($providers)->map(fn ($p) => $labels[$p] ?? $p)->join(', ');
    }

    public function pauseCampaign(): void
    {
        $this->record->update(['status' => 'paused']);
        $this->record->refresh();

        \Filament\Notifications\Notification::make()
            ->title(__('Campaign paused'))
            ->success()
            ->send();
    }

    public function resumeCampaign(): void
    {
        $this->record->update(['status' => 'sending']);
        $this->record->refresh();

        \Filament\Notifications\Notification::make()
            ->title(__('Campaign resumed'))
            ->success()
            ->send();
    }
}
