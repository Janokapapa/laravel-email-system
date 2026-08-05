<?php

namespace JanDev\EmailSystem\Support\Sms;

/**
 * Phone numbers the SMS provider will accept.
 *
 * Named for what it does rather than "audience": in this app an audience is a
 * CSV-imported list, and the casino-side notion of player filters (deposit
 * count, last login, consent) does not apply to people who are not customers yet.
 * What survives the port is the part that decides whether a number is sendable
 * at all.
 *
 * A missing country code is rejected rather than guessed: the provider would
 * guess a different one, and a wrongly-guessed country is both a wasted message
 * and a wrong price.
 */
final class SmsPhone
{
    /** E.164: a plus, a non-zero country digit, then 6 to 14 more digits. */
    public const E164_REGEX = '/^\+[1-9][0-9]{6,14}$/';

    /** The same rule for a SQL REGEXP clause. Note `[+]`, so no escape can be lost. */
    public const E164_SQL_REGEX = '^[+][1-9][0-9]{6,14}$';

    /**
     * Whether the number can be handed to the provider AS-IS.
     *
     * Deliberately stricter than normalise(): "00447700900123" is a valid number
     * but the provider will not take it in that form, so this says no while
     * normalise() converts it. Callers that store or send should normalise
     * first; callers that validate what is already stored use this.
     */
    public static function isSendable(?string $phone): bool
    {
        if ($phone === null) {
            return false;
        }

        $clean = preg_replace('/[\s\-().]/', '', trim($phone)) ?? '';

        return preg_match(self::E164_REGEX, $clean) === 1;
    }

    /**
     * The number in the form the provider gets, or null when it is unusable.
     *
     * Formatting characters a human might type are tolerated; a missing country
     * code is not.
     *
     * A leading 00 is accepted as the international prefix and rewritten to '+'.
     * That is how most European exports write an international number, and
     * rejecting it silently dropped every row of such a file on import. A bare
     * national number (07700900123) is still refused: the country would have to
     * be guessed, and a guess here means texting a stranger.
     */
    public static function normalise(?string $phone, ?string $country = null): ?string
    {
        if ($phone === null) {
            return null;
        }

        $clean = preg_replace('/[\s\-().]/', '', trim($phone)) ?? '';

        // 00 + country code is the same number as + country code.
        if (str_starts_with($clean, '00')) {
            $clean = '+' . substr($clean, 2);
        }

        if (preg_match(self::E164_REGEX, $clean) === 1) {
            return $clean;
        }

        return self::withCountry($clean, $country);
    }

    /**
     * Dialling code per country, for the countries the import offers.
     *
     * Kept here rather than derived from the price list: a price map is about
     * what a message costs, and a missing price must not change what a number
     * means.
     */
    public const DIAL_CODES = [
        'GB' => '44', 'US' => '1',  'DE' => '49', 'FR' => '33', 'ES' => '34',
        'IT' => '39', 'NL' => '31', 'BE' => '32', 'AT' => '43', 'CH' => '41',
        'IE' => '353', 'SE' => '46', 'NO' => '47', 'DK' => '45', 'FI' => '358',
        'PT' => '351', 'GR' => '30', 'PL' => '48', 'CZ' => '420', 'SK' => '421',
        'HU' => '36', 'RO' => '40', 'BG' => '359', 'HR' => '385', 'SI' => '386',
        'RS' => '381', 'LT' => '370', 'LV' => '371', 'EE' => '372', 'CA' => '1',
        'AU' => '61', 'NZ' => '64', 'BR' => '55', 'MX' => '52', 'JP' => '81',
        'IN' => '91', 'ZA' => '27', 'AE' => '971', 'TR' => '90', 'UA' => '380',
    ];

    /**
     * A number written without any international prefix, read against a known
     * country.
     *
     * This is not the guessing the class refuses to do elsewhere: the country
     * comes from the operator or from a column in the same file, so nothing is
     * inferred from the digits. Exports routinely write "447891070032" or
     * "07891070032" for the same GB number, and without this every such row is
     * dropped.
     */
    private static function withCountry(string $clean, ?string $country): ?string
    {
        if ($country === null) {
            return null;
        }

        $dial = self::DIAL_CODES[strtoupper(trim($country))] ?? null;
        if ($dial === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $clean) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, $dial)) {
            // Already carries its country code, just no plus.
            $candidate = '+' . $digits;
        } elseif (str_starts_with($digits, '0')) {
            // National trunk prefix: dropped, not kept, when going international.
            $candidate = '+' . $dial . substr($digits, 1);
        } else {
            $candidate = '+' . $dial . $digits;
        }

        return preg_match(self::E164_REGEX, $candidate) === 1 ? $candidate : null;
    }

    /**
     * Parse a hand-typed list of test numbers, one per line or comma-separated.
     *
     * Unusable entries are dropped rather than reported: this feeds a test-send
     * box, and a typo should not block the two numbers that were right.
     *
     * @return list<string>
     */
    public static function parseList(string $raw): array
    {
        $parts = preg_split('/[,;\r\n]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $phone = self::normalise($part);
            if ($phone !== null && !in_array($phone, $out, true)) {
                $out[] = $phone;
            }
        }

        return $out;
    }

    /**
     * The dialling prefix used to price a number, longest first.
     *
     * Returns the leading digits with the plus, e.g. '+358' - which is what the
     * pricing map is keyed on.
     */
    public static function prefix(string $phone, int $digits = 3): string
    {
        $normalised = self::normalise($phone) ?? $phone;

        return substr($normalised, 0, $digits + 1);
    }
}
