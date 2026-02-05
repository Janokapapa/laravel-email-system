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
        'reference_type',
        'reference_id',
        'recipient',
        'subject',
        'message',
        'sender',
        'cc',
        'bcc',
        'status',
        'opened',
        'opened_at',
        'clicked',
        'clicked_at',
        'error',
        'mailgun_message_id',
        'bounce_type',
        'bounce_reason',
        'bounced_at',
        'complained',
        'complained_at',
    ];

    protected $casts = [
        'opened' => 'boolean',
        'opened_at' => 'datetime',
        'clicked' => 'boolean',
        'clicked_at' => 'datetime',
        'bounced_at' => 'datetime',
        'complained' => 'boolean',
        'complained_at' => 'datetime',
    ];

    public function emailTemplate()
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function emailAudienceGroup()
    {
        return $this->belongsTo(EmailAudienceGroup::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'sent' => 'Sent',
            'queued' => 'Queued',
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

    public function sendEmail(): void
    {
        dispatch(new SendQueuedEmail($this));
    }
}
