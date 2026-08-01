<?php

namespace JanDev\EmailSystem\Filament\Resources\CampaignResource\Pages;

use JanDev\EmailSystem\Filament\Resources\CampaignResource;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Jobs\DispatchCampaign;
use JanDev\EmailSystem\Jobs\DispatchSmsCampaign;
use JanDev\EmailSystem\Support\CampaignFilterBuilder;
use Illuminate\Support\Facades\DB;
use Filament\Resources\Pages\Page;

class ViewCampaign extends Page
{
    protected static string $resource = CampaignResource::class;

    protected string $view = 'email-system::filament.pages.view-campaign';

    public $record;

    public bool $showClickedModal = false;

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
                SUM(CASE WHEN status IN ('sent','spooled','delivered') THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
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

    public function cancelSchedule(): void
    {
        $this->record->update([
            'status'       => 'new',
            'scheduled_at' => null,
        ]);
        $this->record->refresh();

        \Filament\Notifications\Notification::make()
            ->title(__('Schedule cancelled'))
            ->body(__('Campaign reverted to draft status.'))
            ->success()
            ->send();

        $this->redirect(CampaignResource::getUrl('edit', ['record' => $this->record]));
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

    public function openClickedModal(): void
    {
        $this->showClickedModal = true;
    }

    public function closeClickedModal(): void
    {
        $this->showClickedModal = false;
    }

    public function getClickedEmails(): \Illuminate\Support\Collection
    {
        return EmailLog::where('campaign_id', $this->record->id)
            ->where('clicked', true)
            ->select('recipient', 'clicked_at')
            ->orderByDesc('clicked_at')
            ->get();
    }

    public function exportClickedCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $emails = $this->getClickedEmails();

        return response()->streamDownload(function () use ($emails) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Email', 'Clicked At']);
            foreach ($emails as $log) {
                fputcsv($handle, [
                    $log->recipient,
                    $log->clicked_at ? \Carbon\Carbon::parse($log->clicked_at)->format('Y-m-d H:i:s') : '',
                ]);
            }
            fclose($handle);
        }, 'campaign-' . $this->record->id . '-clicked.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function getVariationStats(): array
    {
        $variations = $this->record->variations ?? [];
        $rows = [];

        // Original (variation_id is NULL)
        $rows[] = [
            'key'      => null,
            'label'    => __('Original'),
            'subject'  => $this->record->subject ?? '',
            'body'     => $this->record->body ?? '',
            'is_original' => true,
        ];

        $idx = 1;
        foreach ($variations as $key => $variation) {
            $rows[] = [
                'key'         => is_string($key) ? $key : (string) $key,
                'label'       => __('Variation') . ' ' . $idx++,
                'subject'     => $variation['subject'] ?? '',
                'body'        => $variation['body'] ?? '',
                'is_original' => false,
            ];
        }

        if (count($rows) <= 1) {
            return [];
        }

        // One aggregated query — group by variation_id including NULL
        $stats = EmailLog::where('campaign_id', $this->record->id)
            ->selectRaw("
                variation_id,
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('sent','spooled','delivered') THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(opened) as opened,
                SUM(clicked) as clicked,
                SUM(unsubscribed) as unsubscribed
            ")
            ->groupBy('variation_id')
            ->get()
            ->keyBy(fn ($r) => $r->variation_id ?? '__null__');

        foreach ($rows as &$row) {
            $key = $row['key'] ?? '__null__';
            $stat = $stats->get($key);
            $sent = (int) ($stat->sent ?? 0);
            $row['sent']         = $sent;
            $row['total']        = (int) ($stat->total ?? 0);
            $row['delivered']    = (int) ($stat->delivered ?? 0);
            $row['failed']       = (int) ($stat->failed ?? 0);
            $row['opened']       = (int) ($stat->opened ?? 0);
            $row['clicked']      = (int) ($stat->clicked ?? 0);
            $row['unsubscribed'] = (int) ($stat->unsubscribed ?? 0);
            $row['open_rate']    = $sent > 0 ? round($row['opened'] / $sent * 100, 1) : 0;
            $row['click_rate']   = $sent > 0 ? round($row['clicked'] / $sent * 100, 1) : 0;
        }
        unset($row);

        return $rows;
    }

    public function retryCampaign(): void
    {
        // Recalculate total_recipients with same filters as QueueEmailsForAudience
        $groups = EmailAudienceGroup::whereIn('id', $this->record->audience_group_ids ?? [])->get();
        $filters = $this->record->custom_field_filters ?? [];
        $total = 0;
        foreach ($groups as $group) {
            $query = $group->audienceUsers()
                ->where('is_active', true)
                ->where('bounced', false)
                ->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('bounced_emails')
                        ->whereColumn('bounced_emails.email', 'audience_users.email');
                });
            CampaignFilterBuilder::applyFilters($query, $filters);
            $total += $query->count();
        }

        $this->record->update(['status' => 'sending', 'total_recipients' => $total]);
        $this->record->refresh();

        // An SMS campaign goes down its own path: the e-mail job would try to
        // spool it through PMTA, which has no idea what a phone number is.
        $this->record->isSms()
            ? DispatchSmsCampaign::dispatch($this->record)
            : DispatchCampaign::dispatch($this->record);

        \Filament\Notifications\Notification::make()
            ->title(__('Campaign retry started'))
            ->body(__('Sending to :count recipients. Already sent emails will be skipped.', ['count' => number_format($total)]))
            ->success()
            ->send();
    }
}
