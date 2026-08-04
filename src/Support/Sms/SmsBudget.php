<?php

namespace JanDev\EmailSystem\Support\Sms;

/**
 * Hard spending stop for SMS campaigns.
 *
 * The daily cap in config counts MESSAGES; this counts MONEY, and the two are
 * not interchangeable: a 3-segment UCS-2 message to Spain costs several times a
 * 1-segment message to the UK, so a campaign can stay under the message cap and
 * still overspend by a multiple.
 *
 * The cap is checked BETWEEN BATCHES while the campaign runs, not only when the
 * author presses send. The pre-send estimate is measured on a template whose
 * placeholders are still unresolved, so the real cost can exceed it; a cap that
 * is only enforced up front is decorative.
 *
 * Pure arithmetic, no DB: the admin preview and the queue worker use the same
 * numbers. Ported from casino_common's common/service/SmsBudget.php so both
 * sides of the fleet enforce spend identically.
 */
final class SmsBudget
{
    /**
     * How many more messages fit in the cap, or null when no cap is configured
     * (unlimited). Zero means stop.
     *
     * A cap with no usable unit price returns zero on purpose: without a price
     * the spend cannot be measured, and continuing would leave the cap
     * unenforced — a silent "unlimited" is exactly the failure the cap exists
     * to prevent.
     */
    public static function remainingMessages(?float $cap, ?float $unitPrice, int $segments, float $spent): ?int
    {
        if ($cap === null) {
            return null;
        }
        // A zero or missing price is a misconfiguration, not a free tariff.
        if ($unitPrice === null || $unitPrice <= 0.0) {
            return 0;
        }

        $perMessage = $unitPrice * max(1, $segments);
        $left = $cap - $spent;
        if ($left <= 0.0) {
            return 0;
        }

        return (int) floor($left / $perMessage);
    }

    /**
     * The stop signal used between batches.
     */
    public static function isExhausted(?float $cap, ?float $unitPrice, int $segments, float $spent): bool
    {
        $remaining = self::remainingMessages($cap, $unitPrice, $segments, $spent);

        return $remaining !== null && $remaining <= 0;
    }

    /**
     * Money spent by a finished batch. Reports 0.0 when no price is configured,
     * so an uncapped campaign on a tenant without a tariff still runs and simply
     * reports no cost, instead of accumulating a fabricated one.
     */
    public static function spendFor(int $messages, int $segments, ?float $unitPrice): float
    {
        if ($unitPrice === null || $unitPrice <= 0.0) {
            return 0.0;
        }

        return max(0, $messages) * max(1, $segments) * $unitPrice;
    }

    /**
     * The configured money cap for a campaign run, or null when uncapped.
     * Zero/absent config means uncapped (the message cap still applies).
     */
    public static function configuredCap(): ?float
    {
        $cap = (float) SmsConfig::get('email-system.sms.spend_cap', 0);

        return $cap > 0.0 ? $cap : null;
    }

    /**
     * The single price a spend cap is measured with, given per-country rates.
     *
     * There is no one "unit price" when Spain costs twice the UK, so the cap uses
     * the DEAREST configured rate. That is deliberately pessimistic: stopping a
     * campaign slightly early costs nothing, while overspending cannot be undone
     * once the provider has accepted the messages.
     *
     * @param array<string, float> $prices prefix => price per segment
     */
    public static function worstCasePrice(array $prices): ?float
    {
        $usable = array_filter(
            array_map(static fn ($p) => is_numeric($p) ? (float) $p : 0.0, $prices),
            static fn (float $p) => $p > 0.0
        );

        return $usable === [] ? null : max($usable);
    }
}
