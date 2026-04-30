<?php

namespace JanDev\EmailSystem\Jobs;

use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\EmailTemplate;
use JanDev\EmailSystem\Models\JobTracker;
use JanDev\EmailSystem\Support\ProviderResolver;
use JanDev\EmailSystem\Support\SenderResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

use function JanDev\EmailSystem\resolve_callback;

class QueueEmailsForAudience implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    protected ?int $templateId;
    protected int $audienceGroupId;
    protected array $skipProviders;
    protected ?int $userId;
    protected ?string $senderName;

    // Campaign-specific fields
    protected ?int $campaignId = null;
    protected ?string $campaignSubject = null;
    protected ?string $campaignBody = null;
    protected array $campaignVariations = [];
    protected ?string $senderAddress = null;
    protected ?string $senderDisplayName = null;
    protected ?string $replyTo = null;
    protected string $contentType = 'html';
    protected array $customFieldFilters = [];

    public function __construct(
        ?int $templateId,
        int $audienceGroupId,
        array $skipProviders = [],
        ?int $userId = null,
        ?string $senderName = null,
        ?int $campaignId = null,
        ?string $campaignSubject = null,
        ?string $campaignBody = null,
        ?string $senderAddress = null,
        array $campaignVariations = [],
        string $contentType = 'html',
        ?string $senderDisplayName = null,
        ?string $replyTo = null,
        array $customFieldFilters = [],
    ) {
        $this->templateId = $templateId;
        $this->audienceGroupId = $audienceGroupId;
        $this->skipProviders = $skipProviders;
        $this->userId = $userId;
        $this->senderName = $senderName;
        $this->campaignId = $campaignId;
        $this->campaignSubject = $campaignSubject;
        $this->campaignBody = $campaignBody;
        $this->senderAddress = $senderAddress;
        $this->campaignVariations = $campaignVariations;
        $this->contentType = $contentType;
        $this->senderDisplayName = $senderDisplayName;
        $this->replyTo = $replyTo;
        $this->customFieldFilters = $customFieldFilters;
    }

    public function handle(): void
    {
        $startTime = microtime(true);

        // Load template if templateId given; use campaign content otherwise
        $template = $this->templateId ? EmailTemplate::with('variations')->findOrFail($this->templateId) : null;
        $audienceGroup = EmailAudienceGroup::findOrFail($this->audienceGroupId);

        $label = $this->campaignId
            ? "Campaign #{$this->campaignId} → {$audienceGroup->name}"
            : "Queueing — {$template->name} → {$audienceGroup->name}";

        Log::channel('queue')->info("QueueEmailsForAudience: Starting for {$label}");

        $totalUsers = \JanDev\EmailSystem\Support\CampaignFilterBuilder::applyFilters(
            $audienceGroup->audienceUsers()
                ->where('is_active', true)
                ->where('bounced', false)
                ->whereNotExists(function ($sub) {
                    $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                        ->from('bounced_emails')
                        ->whereColumn('bounced_emails.email', 'audience_users.email');
                }),
            $this->customFieldFilters
        )->count();

        $trackerMeta = [
            'template_id' => $this->templateId,
            'group_id'    => $this->audienceGroupId,
            'sender_name' => $this->senderName,
            'campaign_id' => $this->campaignId,
        ];

        $tracker = JobTracker::start('email_queue', $label, $totalUsers, $trackerMeta);

        // Get additional blocked emails from config callback (e.g. custom opt-out list)
        $additionalBlocked = [];
        $blockedCallback = resolve_callback(config('email-system.blocked_emails_callback'));
        if ($blockedCallback) {
            $additionalBlocked = $blockedCallback();
        }

        // Build O(1) lookup for callback-blocked emails only
        $additionalBlockedEmails = !empty($additionalBlocked) ? array_flip(array_unique($additionalBlocked)) : [];

        Log::channel('queue')->info("QueueEmailsForAudience: Additional blocked (callback): " . count($additionalBlockedEmails));

        // Get emails already sent/queued/spooled — scope by campaign_id when available
        // to prevent duplicate sends within the same campaign (and avoid cross-campaign interference)
        $alreadySentQuery = $this->campaignId
            ? EmailLog::where('campaign_id', $this->campaignId)
            : EmailLog::where('email_template_id', $this->templateId);

        $alreadySentEmails = array_flip(
            $alreadySentQuery
                ->whereIn('status', ['sent', 'queued', 'spooled'])
                ->pluck('recipient')
                ->toArray()
        );

        Log::channel('queue')->info("QueueEmailsForAudience: Already sent/queued: " . count($alreadySentEmails));

        // Resolve sender address: campaign sender_address takes priority over global config
        $sender = $this->senderAddress ?? config('email-system.from.address');
        $senderDisplayName = $this->senderDisplayName;
        $replyTo = $this->replyTo;

        // Resolve content type: campaign/constructor value, or template's content_type
        $contentType = $this->contentType;
        if ($contentType === 'html' && $template && ($template->content_type ?? 'html') !== 'html') {
            $contentType = $template->content_type;
        }

        // Resolve initial status based on sender type
        // PMTA emails go directly to 'spooled' to bypass SendQueuedEmails
        $initialStatus = 'queued';
        if ($this->senderName) {
            $senderConfig = SenderResolver::get($this->senderName);
            if ($senderConfig && ($senderConfig['type'] ?? '') === 'pmta') {
                $initialStatus = 'spooled';
            }
        }

        // Build content pool: campaign content OR template (with variations)
        $contentPool = [];

        if ($this->campaignId && $this->campaignBody !== null) {
            // Campaign mode: original content + campaign variations
            $contentPool[] = [
                'subject'      => $this->campaignSubject ?? '',
                'body'         => $this->campaignBody ?? '',
                'variation_id' => null,
            ];
            foreach ($this->campaignVariations as $key => $variation) {
                $subject = trim($variation['subject'] ?? '');
                $body = $variation['body'] ?? '';
                if ($subject === '' && strip_tags($body) === '') {
                    continue;
                }
                $contentPool[] = [
                    'subject'      => $subject,
                    'body'         => $body,
                    'variation_id' => is_string($key) ? $key : (string) $key,
                ];
            }
        } elseif ($template) {
            // Template mode: original + variations
            $contentPool[] = [
                'subject'      => $template->subject,
                'body'         => $template->body,
                'variation_id' => null,
            ];
            foreach ($template->variations as $variation) {
                $contentPool[] = [
                    'subject'      => $variation->subject,
                    'body'         => $variation->body,
                    'variation_id' => $variation->id,
                ];
            }
        } else {
            Log::channel('queue')->error("QueueEmailsForAudience: No content source (no template, no campaign body)");
            $tracker->markFailed('No content source');
            return;
        }

        // Prepare batch insert data
        $batchData = [];
        $batchSize = 1000;
        $queuedCount = 0;
        $skippedCount = 0;
        $providerSkippedCount = 0;
        $alreadySentSkippedCount = 0;

        // Process in chunks to avoid memory issues
        // NOT EXISTS subquery excludes emails in the global bounce registry (hard bounces + complaints)
        // without loading the entire bounced_emails table into PHP memory.
        \JanDev\EmailSystem\Support\CampaignFilterBuilder::applyFilters(
            $audienceGroup->audienceUsers()
                ->where('is_active', true)
                ->where('bounced', false)
                ->whereNotExists(function ($sub) {
                    $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                        ->from('bounced_emails')
                        ->whereColumn('bounced_emails.email', 'audience_users.email');
                }),
            $this->customFieldFilters
        )->chunkById(1000, function ($users) use (
                $template, $audienceGroup, $sender, $additionalBlockedEmails, &$alreadySentEmails,
                &$batchData, &$queuedCount, &$skippedCount, &$providerSkippedCount, &$alreadySentSkippedCount, $batchSize,
                $initialStatus, $tracker, $contentPool, $contentType, $senderDisplayName, $replyTo
            ) {
                foreach ($users as $user) {
                    // Skip if already sent/queued for this template/campaign
                    if (isset($alreadySentEmails[$user->email])) {
                        $alreadySentSkippedCount++;
                        continue;
                    }

                    // Skip by provider group if configured
                    if (!empty($this->skipProviders) && in_array(ProviderResolver::resolve($user->email), $this->skipProviders, true)) {
                        $providerSkippedCount++;
                        continue;
                    }

                    // Skip if blocked by callback (custom opt-out list)
                    if (isset($additionalBlockedEmails[$user->email])) {
                        $skippedCount++;
                        continue;
                    }

                    // Skip ZeroBounce invalid emails
                    $zbStatus = $user->zerobounce_status ?? null;
                    if ($zbStatus === 'invalid') {
                        $skippedCount++;
                        continue;
                    }

                    // Pick content: random when pool > 1, otherwise the single entry
                    $content = count($contentPool) > 1
                        ? $contentPool[array_rand($contentPool)]
                        : $contentPool[0];

                    $batchData[] = [
                        'email_template_id'       => $this->templateId,
                        'email_audience_group_id' => $audienceGroup->id,
                        'campaign_id'             => $this->campaignId,
                        'recipient'               => $user->email,
                        'recipient_name'          => $user->name,
                        'subject'                 => $user->resolvePlaceholders($content['subject']),
                        'message'                 => $user->resolvePlaceholders($content['body']),
                        'sender'                  => $sender,
                        'sender_name'             => $this->senderName,
                        'sender_display_name'     => $senderDisplayName,
                        'reply_to'                => $replyTo,
                        'content_type'            => $contentType,
                        'variation_id'            => $content['variation_id'],
                        'status'                  => $initialStatus,
                        'created_at'              => now(),
                        'updated_at'              => now(),
                    ];
                    $queuedCount++;

                    // Track in-memory to prevent duplicates within the same job run
                    // (e.g. duplicate subscriber rows in the same audience group)
                    $alreadySentEmails[$user->email] = true;

                    // Insert in batches
                    if (count($batchData) >= $batchSize) {
                        EmailLog::insert($batchData);
                        $batchData = [];
                    }
                }

                $tracker->incrementProgress($users->count());
            });

        // Insert remaining batch
        if (!empty($batchData)) {
            EmailLog::insert($batchData);
        }

        $tracker->markCompleted();

        $duration = round(microtime(true) - $startTime, 2);

        Log::channel('queue')->info("QueueEmailsForAudience completed: {$queuedCount} queued, {$alreadySentSkippedCount} already sent, {$skippedCount} blocked, {$providerSkippedCount} provider-skipped in {$duration}s");

        // Send notification callback if configured
        $notificationCallback = resolve_callback(config('email-system.queue_completion_callback'));
        if ($notificationCallback) {
            $notificationCallback($this->userId, [
                'queued' => $queuedCount,
                'skipped' => $skippedCount,
                'already_sent_skipped' => $alreadySentSkippedCount,
                'provider_skipped' => $providerSkippedCount,
                'duration' => $duration,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Mark any running tracker for this job as failed
        $query = JobTracker::where('type', 'email_queue')
            ->where('status', 'running')
            ->where('meta->group_id', $this->audienceGroupId);

        if ($this->templateId) {
            $query->where('meta->template_id', $this->templateId);
        } elseif ($this->campaignId) {
            $query->where('meta->campaign_id', $this->campaignId);
        }

        $query->each(fn ($t) => $t->markFailed($exception->getMessage()));

        // Mark campaign as failed when campaignId is set
        if ($this->campaignId) {
            $campaign = \JanDev\EmailSystem\Models\Campaign::find($this->campaignId);
            if ($campaign && in_array($campaign->status, ['new', 'sending'])) {
                $campaign->update(['status' => 'failed']);
            }
        }

        Log::channel('queue')->error("QueueEmailsForAudience failed: " . $exception->getMessage());

        $failureCallback = resolve_callback(config('email-system.queue_failure_callback'));
        if ($failureCallback) {
            $failureCallback($this->userId, $exception->getMessage());
        }
    }
}
