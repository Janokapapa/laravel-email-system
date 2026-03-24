<?php

namespace JanDev\EmailSystem\Observers;

use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\BouncedEmail;

class AudienceUserObserver
{
    /**
     * Handle the AudienceUser "created" event.
     *
     * If the newly created user's email is in the global bounce registry,
     * immediately mark them as bounced and inactive — without triggering
     * further observer events (updateQuietly).
     */
    public function created(AudienceUser $audienceUser): void
    {
        $bouncedEmail = BouncedEmail::where('email', strtolower($audienceUser->email))->first();

        if ($bouncedEmail) {
            $audienceUser->updateQuietly([
                'bounced' => true,
                'bounce_type' => $bouncedEmail->bounce_type,
                'bounce_reason' => 'Global bounce registry: ' . ($bouncedEmail->bounce_reason ?? 'hard bounce'),
                'bounced_at' => $bouncedEmail->bounced_at,
                'is_active' => false,
                'zerobounce_status' => 'bounced',
            ]);
            return;
        }

        // Copy ZeroBounce status from another group if this user has no ZB data yet
        if (empty($audienceUser->zerobounce_status) || $audienceUser->zerobounce_status === 'unverified') {
            $existing = AudienceUser::where('email', $audienceUser->email)
                ->where('id', '!=', $audienceUser->id)
                ->whereNotNull('zerobounce_status')
                ->whereNotIn('zerobounce_status', ['unverified'])
                ->orderByDesc('zerobounce_checked_at')
                ->first(['zerobounce_status', 'zerobounce_sub_status', 'zerobounce_checked_at']);

            if ($existing) {
                $audienceUser->updateQuietly([
                    'zerobounce_status' => $existing->zerobounce_status,
                    'zerobounce_sub_status' => $existing->zerobounce_sub_status,
                    'zerobounce_checked_at' => $existing->zerobounce_checked_at,
                ]);
            }
        }
    }
}
