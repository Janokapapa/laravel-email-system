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
        'sender_name',
        'sender_address',
        'sender_display_name',
        'email_template_id',
        'subject',
        'body',
        'audience_group_ids',
        'skip_yahoo',
        'total_recipients',
        'sent_count',
        'failed_count',
        'current_step',
        'sent_at',
    ];

    protected $casts = [
        'audience_group_ids' => 'array',
        'skip_yahoo' => 'boolean',
        'total_recipients' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
        'current_step' => 'integer',
        'sent_at' => 'datetime',
    ];

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
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('sent', 'spooled') THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            ")
            ->first();

        $this->total_recipients = (int) ($counts->total ?? 0);
        $this->sent_count = (int) ($counts->sent ?? 0);
        $this->failed_count = (int) ($counts->failed ?? 0);
        $this->save();
    }

    public function updateStatusFromCounts(): void
    {
        if ($this->total_recipients === 0) {
            $this->status = 'sent';
        } elseif ($this->sent_count >= $this->total_recipients) {
            $this->status = 'sent';
        } elseif ($this->failed_count >= $this->total_recipients) {
            $this->status = 'failed';
        } elseif ($this->failed_count > 0) {
            $this->status = 'partial';
        } else {
            $this->status = 'sent';
        }

        $this->save();
    }
}
