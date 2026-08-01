<?php

namespace JanDev\EmailSystem\Console\Commands;

use JanDev\EmailSystem\Jobs\DispatchCampaign;
use JanDev\EmailSystem\Jobs\DispatchSmsCampaign;
use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Support\CampaignFilterBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchScheduledCampaigns extends Command
{
    protected $signature = 'email:dispatch-scheduled-campaigns';
    protected $description = 'Dispatch campaigns scheduled to be sent at or before the current time';

    public function handle(): int
    {
        $due = Campaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($due->isEmpty()) {
            $this->info('No scheduled campaigns due for sending.');
            return 0;
        }

        foreach ($due as $campaign) {
            $this->dispatchCampaign($campaign);
        }

        return 0;
    }

    private function dispatchCampaign(Campaign $campaign): void
    {
        $groupIds = $campaign->audience_group_ids ?? [];
        $filters  = $campaign->custom_field_filters ?? [];

        // Load audience groups
        $groups = EmailAudienceGroup::whereIn('id', $groupIds)->get()->keyBy('id');

        // Validate all groups still exist
        $missing = collect($groupIds)->filter(fn ($id) => !$groups->has($id))->count();

        if ($missing === count($groupIds)) {
            // All groups are missing — cannot send
            $campaign->update(['status' => 'failed']);
            Log::channel('queue')->warning(
                "DispatchScheduledCampaigns: Campaign #{$campaign->id} ({$campaign->name}) — all audience groups missing, marked failed."
            );
            $this->warn("Campaign #{$campaign->id} ({$campaign->name}): all audience groups missing, marked failed.");
            return;
        }

        if ($missing > 0) {
            Log::channel('queue')->warning(
                "DispatchScheduledCampaigns: Campaign #{$campaign->id} — {$missing} group(s) missing, continuing with remaining groups."
            );
        }

        // 1. Calculate total_recipients FIRST (before status change — prevents FinalizeCampaigns race)
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

        // 2. Atomic status guard: only proceed if campaign is still 'scheduled'
        $updated = Campaign::where('id', $campaign->id)
            ->where('status', 'scheduled')
            ->update([
                'status'           => 'sending',
                'total_recipients' => $total,
                'sent_at'          => now(),
            ]);

        if ($updated === 0) {
            Log::channel('queue')->warning(
                "DispatchScheduledCampaigns: Campaign #{$campaign->id} no longer 'scheduled' (race condition), skipping."
            );
            return;
        }

        $campaign->refresh();

        // 3. Dispatch DispatchCampaign job (wrapped in try-catch to revert on failure)
        try {
            $campaign->isSms()
                ? DispatchSmsCampaign::dispatch($campaign)
                : DispatchCampaign::dispatch($campaign);

            Log::channel('queue')->info(
                "DispatchScheduledCampaigns: Dispatched campaign #{$campaign->id} ({$campaign->name}) to {$total} recipients."
            );
            $this->info("Dispatched campaign #{$campaign->id} ({$campaign->name}) — {$total} recipients.");
        } catch (\Throwable $e) {
            // Revert status to 'scheduled' so it will be retried next minute
            Campaign::where('id', $campaign->id)
                ->where('status', 'sending')
                ->update([
                    'status'           => 'scheduled',
                    'total_recipients' => 0,
                    'sent_at'          => null,
                ]);

            Log::channel('queue')->error(
                "DispatchScheduledCampaigns: Failed to dispatch campaign #{$campaign->id}: " . $e->getMessage()
            );
            $this->error("Failed to dispatch campaign #{$campaign->id}: " . $e->getMessage());
        }
    }
}
