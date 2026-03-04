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
            ]);
        }
    }
}
