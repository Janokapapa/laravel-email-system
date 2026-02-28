<?php

namespace JanDev\EmailSystem\Filament\Resources\CampaignResource\Pages;

use JanDev\EmailSystem\Filament\Resources\CampaignResource;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Models\EmailLog;
use Filament\Resources\Pages\Page;

class ViewCampaign extends Page
{
    protected static string $resource = CampaignResource::class;

    protected static string $view = 'email-system::filament.pages.view-campaign';

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
                SUM(CASE WHEN bounce_type = 'soft' THEN 1 ELSE 0 END) as soft_bounce
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
                MAX(created_at) as last_sent
            ")
            ->groupBy('email_audience_group_id')
            ->get();
    }

    public function getListNames(): string
    {
        $groupIds = $this->record->audience_group_ids ?? [];
        return collect($groupIds)->map(function ($id) {
            $group = EmailAudienceGroup::find($id);
            return $group ? $group->name : __('Deleted');
        })->join(', ') ?: '—';
    }

    public function getSkippedProviders(): string
    {
        $providers = $this->record->skip_providers ?? [];
        if (empty($providers)) return '';
        $labels = ['yahoo' => 'Yahoo', 'microsoft' => 'Microsoft', 'gmail' => 'Gmail', 'icloud' => 'iCloud'];
        return collect($providers)->map(fn ($p) => $labels[$p] ?? $p)->join(', ');
    }
}
