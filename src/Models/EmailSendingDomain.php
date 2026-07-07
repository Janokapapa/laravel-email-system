<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks warmup state for a sending (From/DKIM) domain.
 *
 * first_sent_at anchors the warmup curve (see WarmupLimiter). Optional
 * per-domain overrides let an operator disable warmup or raise the ceiling
 * for a specific domain without touching global config.
 */
class EmailSendingDomain extends Model
{
    protected $table = 'email_sending_domains';

    protected $fillable = [
        'domain',
        'first_sent_at',
        'warmup_enabled',
        'max_daily',
    ];

    protected $casts = [
        'first_sent_at' => 'datetime',
        'warmup_enabled' => 'boolean',
        'max_daily' => 'integer',
    ];
}
