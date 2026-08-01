<?php

namespace JanDev\EmailSystem\Support\Sms;

use JanDev\UserManagement\Models\Setting;
use Throwable;

/**
 * Per-country SMS pricing.
 *
 * The provider does not charge one rate: Spain is roughly twice the UK. A single
 * flat price would mis-state the cost of any campaign whose audience is not
 * concentrated in one country - and the direction of the error depends on the
 * mix, so it cannot even be corrected with a safety margin.
 *
 * Prices are keyed by dialling-code prefix and resolved longest-match-first, so a
 * specific area can override its own country code.
 *
 * Editable in the admin under the `sms` / `prices` setting, e.g.
 *   {"44": 0.044, "46": 0.069, "default": 0.069}
 */
final class SmsPricing
{
    /**
     * Rates quoted by the provider, 2026-07-31, in EUR per segment.
     *
     * NOTE on "1": the quote says Canada. The dialling code +1 also covers the
     * United States, whose rate was not quoted, so any US number would be priced
     * as Canadian. Left in deliberately - a wrong-but-close price beats no price -
     * but it is the one entry to revisit when the US rate is known.
     */
    private const DEFAULT_PRICES = [
        '44' => 0.044,   // United Kingdom
        '46' => 0.069,   // Sweden
        '31' => 0.069,   // Netherlands
        '47' => 0.059,   // Norway
        '1' => 0.05,     // Canada (and, unquoted, the US)
        '358' => 0.068,  // Finland
        '33' => 0.065,   // France
        '34' => 0.098,   // Spain
    ];

    /**
     * Rate used for a country with no quoted price. The most expensive quoted
     * rate, on purpose: an unknown country should over-estimate rather than
     * present a campaign as cheaper than the invoice will be.
     */
    private const FALLBACK = 0.098;

    /**
     * Price per segment for a phone number, or null when pricing is not
     * configured at all.
     */
    public static function forPhone(string $phone): ?float
    {
        $map = self::map();
        if ($map === []) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        $best = '';
        foreach ($map as $prefix => $price) {
            $prefix = (string) $prefix;
            if ($prefix === 'default') {
                continue;
            }
            if ($prefix !== '' && str_starts_with($digits, $prefix) && strlen($prefix) > strlen($best)) {
                $best = $prefix;
            }
        }

        if ($best !== '') {
            return (float) $map[$best];
        }

        return isset($map['default']) ? (float) $map['default'] : self::FALLBACK;
    }

    /**
     * Price for a bare dialling prefix as stored in an audience bucket (e.g.
     * '+358' or '+46'). Same resolution as forPhone().
     */
    public static function forPrefix(string $prefix): ?float
    {
        return self::forPhone($prefix);
    }

    /**
     * The configured price map, falling back to the quoted rates.
     *
     * Reads the admin setting when one is available and falls back silently
     * otherwise: this is called from the campaign form and from unit tests that
     * run without a database, and neither should fail over pricing config.
     *
     * @return array<array-key, float>
     */
    public static function map(): array
    {
        $raw = null;

        // Both lookups are guarded: this is called from the campaign form, from a
        // queue worker, and from unit tests that run without a booted container.
        // A price lookup must never be the reason any of those fails.
        try {
            if (class_exists(Setting::class)) {
                $raw = Setting::get('sms', 'prices', null);
            }
        } catch (Throwable) {
            $raw = null;
        }

        if ($raw === null) {
            try {
                $raw = config('email-system.sms.prices');
            } catch (Throwable) {
                $raw = null;
            }
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($raw) || $raw === []) {
            return self::DEFAULT_PRICES;
        }

        $map = [];
        foreach ($raw as $prefix => $price) {
            if (is_numeric($price)) {
                $map[(string) $prefix] = (float) $price;
            }
        }

        return $map === [] ? self::DEFAULT_PRICES : $map;
    }

    /** Whether any pricing is available, i.e. whether a cost can be quoted at all. */
    public static function isConfigured(): bool
    {
        return self::map() !== [];
    }
}
