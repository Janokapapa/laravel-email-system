<?php

namespace JanDev\EmailSystem\Support;

class ProviderResolver
{
    /**
     * Resolve the email provider group from a recipient email address.
     *
     * Provider groups:
     * - microsoft: hotmail, outlook, live, msn, windowslive
     * - yahoo: yahoo, ymail, aol, aim, verizon
     * - gmail: gmail, googlemail
     * - icloud: icloud, me, mac
     * - default: everything else
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

        if (preg_match('/^(yahoo|ymail|aol|aim|verizon)\./i', $domain)) {
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
