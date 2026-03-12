<?php

namespace JanDev\EmailSystem\Jobs;

use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Services\ZeroBounce;
use JanDev\EmailSystem\Support\CampaignFilterBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600; // 1 hour max for large campaigns

    protected Campaign $campaign;

    /**
     * Injectable ZeroBounce validator for testing.
     * In production this is always null (real API used).
     * Closures are not serializable — only pass in tests via dispatchSync / direct instantiation.
     */
    private readonly ?\Closure $zeroBounceValidator;

    public function __construct(Campaign $campaign, ?\Closure $zeroBounceValidator = null)
    {
        $this->campaign            = $campaign;
        $this->zeroBounceValidator = $zeroBounceValidator;
    }

    public function handle(): void
    {
        $campaign = $this->campaign->fresh();

        if (!$campaign) {
            Log::channel('queue')->warning("DispatchCampaign: Campaign {$this->campaign->id} not found");
            return;
        }

        $groupIds = $campaign->audience_group_ids ?? [];

        Log::channel('queue')->info("DispatchCampaign: Campaign {$campaign->id} ({$campaign->name}), groups: " . implode(',', $groupIds));

        // Process each audience group sequentially to avoid race conditions
        // on campaign status finalization. Try-catch per group to allow partial completion.
        foreach ($groupIds as $groupId) {
            $group = EmailAudienceGroup::find($groupId);

            if (!$group) {
                Log::channel('queue')->warning("DispatchCampaign: Group {$groupId} not found, skipping");
                continue;
            }

            // ── ZeroBounce auto-verification ─────────────────────────────────
            if (ZeroBounce::isEnabled()) {
                $filters = $campaign->custom_field_filters ?? [];
                $uncheckedQuery = AudienceUser::where('email_audience_group_id', $groupId)
                    ->where('is_active', true)
                    ->where('bounced', false)
                    ->where(function ($q) {
                        $q->whereNull('zerobounce_status')
                          ->orWhere('zerobounce_status', ZeroBounce::STATUS_UNVERIFIED);
                    });
                CampaignFilterBuilder::applyFilters($uncheckedQuery, $filters);
                $unchecked = $uncheckedQuery->count();

                if ($unchecked > 0) {
                    Log::channel('queue')->info(
                        "DispatchCampaign: Verifying {$unchecked} unchecked emails in group {$groupId} before sending"
                    );
                    $stats = ZeroBounce::verifyAudienceGroup($groupId, $filters, $this->zeroBounceValidator);
                    Log::channel('queue')->info(
                        "DispatchCampaign: ZeroBounce done — " .
                        "verified={$stats['verified']}, skipped={$stats['skipped']}, errors={$stats['errors']}"
                    );
                }
            }

            try {
                // Dispatch synchronously to ensure sequential processing
                QueueEmailsForAudience::dispatchSync(
                    templateId:          $campaign->email_template_id,
                    audienceGroupId:     (int) $groupId,
                    skipProviders:       $campaign->skip_providers ?? [],
                    userId:              null,
                    senderName:          $campaign->sender_name,
                    campaignId:          $campaign->id,
                    campaignSubject:     $campaign->subject,
                    campaignBody:        $campaign->body,
                    senderAddress:       $campaign->sender_address,
                    campaignVariations:  $campaign->variations ?? [],
                    contentType:         $campaign->content_type ?? 'html',
                    senderDisplayName:   $campaign->sender_display_name,
                    replyTo:             $campaign->reply_to,
                    customFieldFilters:  $campaign->custom_field_filters ?? [],
                );
            } catch (\Throwable $e) {
                Log::channel('queue')->error("DispatchCampaign: Group {$groupId} failed: " . $e->getMessage());
            }
        }

        // After all groups complete, refresh counts and set final status
        $campaign->refreshCounts();
        $campaign->updateStatusFromCounts();

        Log::channel('queue')->info("DispatchCampaign: Campaign {$campaign->id} completed. Status: {$campaign->status}, Sent: {$campaign->sent_count}/{$campaign->total_recipients}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('queue')->error("DispatchCampaign failed for campaign {$this->campaign->id}: " . $exception->getMessage());

        $campaign = $this->campaign->fresh();
        if ($campaign && in_array($campaign->status, ['new', 'sending'])) {
            $campaign->update(['status' => 'failed']);
        }
    }
}
