<?php

namespace JanDev\EmailSystem\Models;

use Illuminate\Database\Eloquent\Model;
use JanDev\EmailSystem\Support\Sms\SmsPhone;

/**
 * Numbers that must never be texted again.
 *
 * Its own table rather than a flag on the audience row, because audiences here
 * are CSV imports that get replaced. A flag would be wiped by the next import and
 * the person who replied STOP would be messaged again on the following campaign,
 * which is both the complaint and the regulatory problem.
 *
 * Stored normalised, so the same number cannot slip back in written differently.
 */
class SmsSuppression extends Model
{
    protected $table = 'sms_suppressions';

    protected $fillable = ['phone', 'reason', 'source'];

    /**
     * Record an opt-out. Idempotent: STOP sent twice is still one opt-out.
     */
    public static function block(string $phone, string $reason = 'stop', ?string $source = null): bool
    {
        $normalised = SmsPhone::normalise($phone);
        if ($normalised === null) {
            return false;
        }

        static::updateOrCreate(
            ['phone' => $normalised],
            ['reason' => $reason, 'source' => $source]
        );

        return true;
    }

    public static function isBlocked(?string $phone): bool
    {
        $normalised = SmsPhone::normalise($phone);
        if ($normalised === null) {
            return false;
        }

        return static::where('phone', $normalised)->exists();
    }

    /**
     * The suppressed subset of a list, normalised.
     *
     * One query for a whole send chunk: checking per recipient would be a query
     * per message, and a campaign is tens of thousands of them.
     *
     * @param list<string> $phones
     * @return array<string, true> normalised phone => true
     */
    public static function blockedAmong(array $phones): array
    {
        $normalised = [];
        foreach ($phones as $phone) {
            $clean = SmsPhone::normalise($phone);
            if ($clean !== null) {
                $normalised[$clean] = true;
            }
        }
        if ($normalised === []) {
            return [];
        }

        $found = static::whereIn('phone', array_keys($normalised))->pluck('phone')->all();

        return array_fill_keys($found, true);
    }
}
