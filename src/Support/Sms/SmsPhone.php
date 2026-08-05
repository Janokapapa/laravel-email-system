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
    public static function normalise(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $clean = preg_replace('/[\s\-().]/', '', trim($phone)) ?? '';

        // 00 + country code is the same number as + country code.
        if (str_starts_with($clean, '00')) {
            $clean = '+' . substr($clean, 2);
        }

        return preg_match(self::E164_REGEX, $clean) === 1 ? $clean : null;
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
