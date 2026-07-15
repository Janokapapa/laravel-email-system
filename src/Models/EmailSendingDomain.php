<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks warmup state for a sending (From/DKIM) domain.
 *
 * first_sent_at anchors the warmup curve (see WarmupLimiter). Optional
 * per-domain overrides let an operator disable warmup or raise the ceiling
 * for a specific domain without touching global config.
 *
 * blocked_providers pauses one or more inbox-provider groups (as classified by
 * ProviderResolver: gmail/yahoo/microsoft/icloud/default) for this domain — the
 * send path defers those recipients instead of handing them to PMTA.
 */
class EmailSendingDomain extends Model
{
    protected $table = 'email_sending_domains';

    protected $fillable = [
        'domain',
        'first_sent_at',
        'warmup_enabled',
        'max_daily',
        'blocked_providers',
    ];

    protected $casts = [
        'first_sent_at' => 'datetime',
        'warmup_enabled' => 'boolean',
        'max_daily' => 'integer',
        'blocked_providers' => 'array',
    ];

    /**
     * Is the given provider group currently suppressed for this domain?
     */
    public function blocksProvider(string $provider): bool
    {
        $blocked = $this->blocked_providers;
        return is_array($blocked) && in_array($provider, $blocked, true);
    }
}
