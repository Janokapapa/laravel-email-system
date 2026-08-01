<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $table = 'campaigns';

    protected $fillable = [
        'name',
        'status',
        'channel',
        'sender_name',
        'sender_address',
        'sender_display_name',
        'reply_to',
        'email_template_id',
        'content_type',
        'subject',
        'body',
        'variations',
        'audience_group_ids',
        'custom_field_filters',
        'skip_providers',
        'total_recipients',
        'sent_count',
        'failed_count',
        'delivered_count',
        'current_step',
        'sent_at',
        'scheduled_at',
    ];

    protected $casts = [
        'variations' => 'array',
        'audience_group_ids' => 'array',
        'custom_field_filters' => 'array',
        'skip_providers' => 'array',
        'total_recipients' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
        'delivered_count' => 'integer',
        'current_step' => 'integer',
        'sent_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';

    /**
     * The channel is fixed once the campaign exists: half the fields mean
     * different things per channel, and switching one mid-life leaves a campaign
     * whose recorded cost and audience belong to the other. Duplicate to switch.
     */
    public function isSms(): bool
    {
        return $this->channel === self::CHANNEL_SMS;
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function getProgressPercent(): int
    {
        if ($this->total_recipients === 0) {
            return 0;
        }

        return (int) round(($this->sent_count / $this->total_recipients) * 100);
    }

    public function refreshCounts(): void
    {
        $counts = EmailLog::where('campaign_id', $this->id)
            ->selectRaw("
                SUM(CASE WHEN status IN ('sent', 'spooled', 'delivered') THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered
            ")
            ->first();

        $this->sent_count = (int) ($counts->sent ?? 0);
        $this->failed_count = (int) ($counts->failed ?? 0);
        $this->delivered_count = (int) ($counts->delivered ?? 0);
        $this->save();
    }

    public function updateStatusFromCounts(): void
    {
        // Don't override paused or scheduled status — only explicit actions should change these
        if (in_array($this->status, ['paused', 'scheduled'])) {
            return;
        }

        $processed = $this->sent_count + $this->failed_count;

        // Check if there are still emails pending processing (queued/spooled)
        $pending = EmailLog::where('campaign_id', $this->id)
            ->whereIn('status', ['queued', 'spooled'])
            ->exists();

        if ($this->total_recipients === 0) {
            $this->status = 'sent';
        } elseif ($this->failed_count >= $this->total_recipients) {
            $this->status = 'failed';
        } elseif ($this->sent_count >= $this->total_recipients) {
            $this->status = 'sent';
        } elseif (!$pending && $processed > 0 && $this->status === 'sending') {
            // All email_logs processed (none queued/spooled), but total_recipients
            // may be higher due to pre-send filtering (ZeroBounce, bounces, etc.)
            $this->status = $this->failed_count > 0 ? 'partial' : 'sent';
        } elseif ($this->failed_count > 0 && $this->sent_count > 0) {
            $this->status = 'partial';
        } elseif ($processed < $this->total_recipients) {
            $this->status = 'sending';
        } else {
            $this->status = 'sent';
        }

        $this->save();
    }
}
