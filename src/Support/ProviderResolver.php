<?php

namespace JanDev\EmailSystem\Support;

class ProviderResolver
{
    /**
     * Resolve the email provider group from a recipient email address.
     *
     * Provider groups:
     * - microsoft: hotmail, outlook, live, msn, windowslive
     * - yahoo: yahoo, ymail, aol, aim, verizon, rocketmail, sky,
     *          btinternet, btopenworld, talk21
     * - gmail: gmail, googlemail
     * - icloud: icloud, me, mac
     * - default: everything else
     *
     * The Yahoo group is a routing group (clean/Proofpoint-free IP pool), not a
     * strict MX classification: rocketmail/sky/verizon are Yahoo-hosted, while
     * the BT family (btinternet/btopenworld/talk21) has since migrated to
     * Openwave. They are grouped here so per-provider routing steers them onto
     * the clean pool (they otherwise fall to 'default' -> the dirty vMTA).
     *
     * The regex is anchored with ^ so that domains like myhotmail.com do NOT match microsoft.
     */
    public static function resolve(string $email): string
    {
        $atPos = strpos($email, '@');
        if ($atPos === false) {
            return 'default';
        }
        $domain = strtolower(substr($email, $atPos + 1));

        if (preg_match('/^(hotmail|outlook|live|msn|windowslive)\./i', $domain)) {
            return 'microsoft';
        }

        if (preg_match('/^(yahoo|ymail|aol|aim|verizon|rocketmail|sky|btinternet|btopenworld|talk21)\./i', $domain)) {
            return 'yahoo';
        }

        if (preg_match('/^(gmail|googlemail)\./i', $domain)) {
            return 'gmail';
        }

        if (preg_match('/^(icloud|me|mac)\./i', $domain)) {
            return 'icloud';
        }

        return 'default';
    }
}
