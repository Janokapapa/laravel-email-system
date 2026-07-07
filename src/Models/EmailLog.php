<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;
use JanDev\EmailSystem\Jobs\SendQueuedEmail;

class EmailLog extends Model
{
    protected $table = 'email_logs';

    protected $fillable = [
        'email_template_id',
        'email_audience_group_id',
        'campaign_id',
        'variation_id',
        'reference_type',
        'reference_id',
        'recipient',
        'recipient_name',
        'subject',
        'message',
        'sender',
        'sender_name',
        'sender_display_name',
        'reply_to',
        'content_type',
        'cc',
        'bcc',
        'status',
        'sent_at',
        'opened',
        'opened_at',
        'clicked',
        'clicked_at',
        'unsubscribed',
        'unsubscribed_at',
        'error',
        'mailgun_message_id',
        'bounce_type',
        'bounce_reason',
        'bounced_at',
        'complained',
        'complained_at',
        'delivered_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened' => 'boolean',
        'opened_at' => 'datetime',
        'clicked' => 'boolean',
        'clicked_at' => 'datetime',
        'unsubscribed' => 'boolean',
        'unsubscribed_at' => 'datetime',
        'bounced_at' => 'datetime',
        'complained' => 'boolean',
        'complained_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function emailTemplate()
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function emailAudienceGroup()
    {
        return $this->belongsTo(EmailAudienceGroup::class);
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'sent' => 'Sent',
            'delivered' => 'Delivered',
            'queued' => 'Queued',
            'spooled' => 'Spooled',
            'failed' => 'Failed',
            default => ucfirst($this->status ?? 'Unknown'),
        };
    }

    public function markAsOpened(): void
    {
        $this->update([
            'opened' => true,
            'opened_at' => now(),
        ]);
    }

    public function markAsClicked(): void
    {
        $this->update([
            'clicked' => true,
            'clicked_at' => now(),
        ]);
    }

    public function markAsDelivered(): void
    {
        $this->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
    }

    public function markAsUnsubscribed(): void
    {
        $this->update([
            'unsubscribed' => true,
            'unsubscribed_at' => now(),
        ]);
    }

    public function sendEmail(): void
    {
        dispatch(new SendQueuedEmail($this));
    }
}
