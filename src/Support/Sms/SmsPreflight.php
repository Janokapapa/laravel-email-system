<?php

namespace JanDev\EmailSystem\Support\Sms;

use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\EmailLog;
use Throwable;

/**
 * Pre-send check of the whole SMS chain.
 *
 * A campaign can fail for reasons that are all invisible from the compose
 * screen: the provider key is wrong, the short-link service is down, a
 * destination country has no price so the spend cap cannot be enforced, or the
 * delivery-report webhook was never enabled so the result will be unknowable.
 * Finding those out one at a time, each after a paid send, is the expensive way.
 *
 * Two severities on purpose:
 *  - FAIL means "do not send" (the campaign would do nothing, or spend
 *    unmeasured money)
 *  - WARN means "send, but know this" (typically: you will not learn what was
 *    delivered)
 *
 * Treating a missing delivery report as fatal would block campaigns over
 * reporting; treating a missing provider key as a warning would let a campaign
 * silently do nothing.
 */
final class SmsPreflight
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** @return array{name: string, severity: string, detail: string} */
    public static function check(string $name, string $severity, string $detail = ''): array
    {
        return ['name' => $name, 'severity' => $severity, 'detail' => $detail];
    }

    /**
     * @param list<array{name: string, severity: string, detail: string}> $checks
     * @return array{sendable: bool, fails: int, warnings: int, checks: array}
     */
    public static function summarise(array $checks): array
    {
        $fails = 0;
        $warnings = 0;
        foreach ($checks as $c) {
            if (($c['severity'] ?? '') === self::FAIL) {
                $fails++;
            } elseif (($c['severity'] ?? '') === self::WARN) {
                $warnings++;
            }
        }

        return [
            // Nothing verified is not the same as everything fine.
            'sendable' => $checks !== [] && $fails === 0,
            'fails' => $fails,
            'warnings' => $warnings,
            'checks' => $checks,
        ];
    }

    /**
     * The spend cap only means something if a price exists to measure it with.
     */
    public static function capCheck(?float $cap, ?float $unitPrice): array
    {
        if ($cap === null) {
            return self::check(
                'spend_cap',
                self::WARN,
                'no money cap set (SMS_SPEND_CAP=0); only the daily message cap applies'
            );
        }
        if ($unitPrice === null || $unitPrice <= 0.0) {
            return self::check(
                'spend_cap',
                self::FAIL,
                'a cap is set but no price is configured, so spend cannot be measured and the cap would not stop anything'
            );
        }

        return self::check(
            'spend_cap',
            self::OK,
            sprintf('cap %s, measured at the dearest rate %s/segment', number_format($cap, 2), number_format($unitPrice, 4))
        );
    }

    /**
     * Run every check against the live configuration.
     *
     * Each probe is guarded: a preflight that dies on the first missing service
     * hides the rest of the report, which is the part the operator needs.
     *
     * @return array{sendable: bool, fails: int, warnings: int, checks: array}
     */
    public static function run(?Campaign $campaign = null): array
    {
        $checks = [];

        // 1. Provider credentials.
        try {
            $checks[] = Mobivate::isConfigured()
                ? self::check('provider', self::OK, 'account id and api key present')
                : self::check('provider', self::FAIL, 'not configured (email-system.sms.account_id / api_key)');
        } catch (Throwable $e) {
            $checks[] = self::check('provider', self::FAIL, 'config unreadable: ' . $e->getMessage());
        }

        // 2. Sender id the recipient sees.
        try {
            $originator = (string) SmsConfig::get('email-system.sms.originator', '');
            $checks[] = $originator !== ''
                ? self::check('originator', self::OK, $originator)
                : self::check('originator', self::FAIL, 'no sender id configured');
        } catch (Throwable $e) {
            $checks[] = self::check('originator', self::FAIL, $e->getMessage());
        }

        // 3. Pricing — needed for the estimate AND for the cap.
        $unitPrice = null;
        try {
            $map = SmsPricing::map();
            $unitPrice = SmsBudget::worstCasePrice($map);
            $checks[] = $map !== []
                ? self::check('pricing', self::OK, count($map) . ' prefixes, dearest ' . number_format((float) $unitPrice, 4))
                : self::check('pricing', self::FAIL, 'no prices configured; cost cannot be quoted');
        } catch (Throwable $e) {
            $checks[] = self::check('pricing', self::FAIL, $e->getMessage());
        }

        // 4. Money cap.
        try {
            $checks[] = self::capCheck(SmsBudget::configuredCap(), $unitPrice);
        } catch (Throwable $e) {
            $checks[] = self::check('spend_cap', self::FAIL, $e->getMessage());
        }

        // 5. Daily message cap.
        try {
            $remaining = SmsCampaignSender::dailyRemaining();
            if ($remaining === null) {
                $checks[] = self::check('daily_cap', self::WARN, 'uncapped (SMS_DAILY_CAP=0)');
            } elseif ($remaining <= 0) {
                $checks[] = self::check('daily_cap', self::FAIL, 'reached for today; nothing would send');
            } else {
                $checks[] = self::check('daily_cap', self::OK, $remaining . ' messages left today');
            }
        } catch (Throwable $e) {
            $checks[] = self::check('daily_cap', self::WARN, 'not readable: ' . $e->getMessage());
        }

        // 6. Short links — a campaign whose links do not mint sends bare URLs.
        try {
            $base = (string) SmsConfig::get('email-system.sms.shortlink.base_url', '');
            $key = (string) SmsConfig::get('email-system.sms.shortlink.key', '');
            $checks[] = ($base !== '' && $key !== '')
                ? self::check('shortlinks', self::OK, 'configured (' . $base . ')')
                : self::check('shortlinks', self::WARN, 'not configured; links go out full length and clicks are not attributed');
        } catch (Throwable $e) {
            $checks[] = self::check('shortlinks', self::WARN, $e->getMessage());
        }

        // 7. Delivery reports — the difference between "accepted" and "arrived".
        try {
            $stored = EmailLog::where('channel', 'sms')
                ->whereNotNull('provider_message_id')
                ->count();
            $reported = EmailLog::where('channel', 'sms')
                ->whereIn('status', ['delivered', 'undelivered'])
                ->count();

            if ($stored === 0) {
                $checks[] = self::check('delivery_reports', self::WARN, 'no sent SMS carries a provider id yet; nothing to match reports against');
            } elseif ($reported === 0) {
                $checks[] = self::check(
                    'delivery_reports',
                    self::WARN,
                    "{$stored} messages have a provider id but no report has ever arrived - is the DR webhook enabled in the provider account?"
                );
            } else {
                $checks[] = self::check('delivery_reports', self::OK, "{$reported} reports applied");
            }
        } catch (Throwable $e) {
            $checks[] = self::check('delivery_reports', self::WARN, 'not readable: ' . $e->getMessage());
        }

        // 8. Opt-out list must be reachable, or a STOP cannot be honoured.
        try {
            $suppressed = \JanDev\EmailSystem\Models\SmsSuppression::query()->count();
            $checks[] = self::check('suppression', self::OK, $suppressed . ' numbers suppressed');
        } catch (Throwable $e) {
            $checks[] = self::check('suppression', self::FAIL, 'suppression list unreadable: ' . $e->getMessage());
        }

        // 9. The campaign itself, when one was given.
        if ($campaign !== null) {
            try {
                $blocked = SmsCampaignSender::blockedReason($campaign);
                $checks[] = $blocked === null
                    ? self::check('campaign', self::OK, 'ready to send')
                    : self::check('campaign', self::FAIL, $blocked);
            } catch (Throwable $e) {
                $checks[] = self::check('campaign', self::FAIL, $e->getMessage());
            }
        }

        return self::summarise($checks);
    }
}
