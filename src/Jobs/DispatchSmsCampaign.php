<?php

namespace JanDev\EmailSystem\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Support\Sms\SmsCampaignSender;

/**
 * Sends an SMS campaign in the background.
 *
 * Unique per campaign, like the e-mail job: two workers picking up the same
 * campaign would text everyone twice, and on this channel that is billed twice
 * and cannot be recalled.
 *
 * @see SmsCampaignSender for what "sendable" means and what gets dropped.
 */
class DispatchSmsCampaign implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;
    public int $uniqueFor = 3600;

    /** @param list<string> $testNumbers */
    public function __construct(
        protected Campaign $campaign,
        protected array $testNumbers = []
    ) {
    }

    public function uniqueId(): string
    {
        return 'dispatch-sms-campaign-' . $this->campaign->id;
    }

    public function handle(): void
    {
        $campaign = $this->campaign->fresh();
        if (!$campaign) {
            Log::warning("DispatchSmsCampaign: campaign {$this->campaign->id} not found");

            return;
        }

        $blocked = SmsCampaignSender::blockedReason($campaign);
        if ($blocked !== null) {
            Log::warning("DispatchSmsCampaign: campaign {$campaign->id} not sent: {$blocked}");
            $campaign->update(['status' => 'failed']);

            return;
        }

        $campaign->update(['status' => 'sending', 'sent_at' => now()]);

        $result = SmsCampaignSender::send($campaign, $this->testNumbers);

        $campaign->update([
            'sent_count' => $result['sent'],
            'failed_count' => $result['failed'],
            'total_recipients' => $result['sent'] + $result['failed'],
            // "sent" means the provider accepted it. Delivery is only knowable
            // from a delivery report, which is a separate callback.
            'status' => $result['failed'] > 0 && $result['sent'] === 0 ? 'failed' : 'sent',
        ]);

        Log::info("DispatchSmsCampaign: campaign {$campaign->id} finished", $result);
    }
}
