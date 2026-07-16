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
        'provider_policies',
    ];

    protected $casts = [
        'first_sent_at' => 'datetime',
        'warmup_enabled' => 'boolean',
        'max_daily' => 'integer',
        'blocked_providers' => 'array',
        'provider_policies' => 'array',
    ];

    /**
     * Is the given provider group currently suppressed for this domain?
     * A provider under an active re-warm policy is NOT hard-blocked.
     */
    public function blocksProvider(string $provider): bool
    {
        $blocked = $this->blocked_providers;
        return is_array($blocked) && in_array($provider, $blocked, true);
    }

    /**
     * Re-warm policy for the given provider, or null when none.
     * e.g. ['daily_cap' => 25, 'engaged_days' => 180]
     */
    public function providerPolicy(string $provider): ?array
    {
        $policies = $this->provider_policies;
        if (is_array($policies) && isset($policies[$provider]) && is_array($policies[$provider])) {
            return $policies[$provider];
        }
        return null;
    }
}
