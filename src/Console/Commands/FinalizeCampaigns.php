<?php

namespace JanDev\EmailSystem\Console\Commands;

use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\EmailLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FinalizeCampaigns extends Command
{
    protected $signature = 'email:finalize-campaigns';
    protected $description = 'Finalize stuck "sending" campaigns that have no pending emails';

    public function handle(): int
    {
        $campaigns = Campaign::where('status', 'sending')->get();

        if ($campaigns->isEmpty()) {
            $this->info('No sending campaigns found.');
            return 0;
        }

        foreach ($campaigns as $campaign) {
            $pending = EmailLog::where('campaign_id', $campaign->id)
                ->whereIn('status', ['queued', 'spooled'])
                ->count();

            if ($pending > 0) {
                $this->line("Campaign #{$campaign->id} ({$campaign->name}): {$pending} pending emails, skipping.");
                continue;
            }

            $oldSent = $campaign->sent_count;
            $oldFailed = $campaign->failed_count;
            $oldStatus = $campaign->status;

            $campaign->refreshCounts();
            $campaign->updateStatusFromCounts();

            $this->info(
                "Campaign #{$campaign->id} ({$campaign->name}): "
                . "{$oldStatus} → {$campaign->status} "
                . "(sent: {$oldSent}→{$campaign->sent_count}, failed: {$oldFailed}→{$campaign->failed_count})"
            );

            Log::channel('queue')->info(
                "FinalizeCampaigns: #{$campaign->id} {$oldStatus} → {$campaign->status} "
                . "(sent: {$oldSent}→{$campaign->sent_count}, failed: {$oldFailed}→{$campaign->failed_count})"
            );
        }

        return 0;
    }
}
