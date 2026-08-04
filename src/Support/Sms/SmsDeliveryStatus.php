<?php

namespace JanDev\EmailSystem\Support\Sms;

/**
 * Reading a provider delivery report.
 *
 * "The provider accepted it" and "the handset received it" are different facts,
 * and only the second one is worth reporting. Without this, campaign statistics
 * say 100% sent forever — which is exactly the number that is never true.
 *
 * Anything not positively known to be delivered is treated as NOT delivered
 * rather than guessed at: over-reporting delivery is worse than under-reporting
 * it, because it is the number nobody goes back to check.
 *
 * Providers disagree on parameter names and casing, so the field readers accept
 * every spelling seen in the wild. Mirrors casino_common's SmsInbound so both
 * sides classify a report the same way.
 */
final class SmsDeliveryStatus
{
    /**
     * Status values that mean the handset actually received the message.
     */
    private const DELIVERED = ['delivered', 'delivrd', 'success', 'ok', '0', '1'];

    /** Parameter names a provider may use for the status. */
    private const STATUS_KEYS = ['status', 'status_text', 'state', 'dlr_status', 'delivery_status'];

    /** Parameter names a provider may use for its own message id. */
    private const ID_KEYS = ['message_id', 'messageid', 'messageId', 'id', 'reference', 'msg_id'];

    /** Parameter names a provider may use for the recipient number. */
    private const RECIPIENT_KEYS = ['recipient', 'to', 'msisdn', 'destination', 'number', 'phone'];

    public static function isDelivered(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::DELIVERED, true);
    }

    /** Lower-cased status from whichever key the provider used, or ''. */
    public static function status(array $params): string
    {
        return strtolower(self::value($params, self::STATUS_KEYS));
    }

    /** Provider message id from whichever key it used, or ''. */
    public static function messageId(array $params): string
    {
        return self::value($params, self::ID_KEYS);
    }

    /**
     * Recipient as a CANONICAL digits-only key for matching.
     *
     * Deliberately not SmsPhone::normalise(): that keeps whatever leading '+'
     * the source had, so stored numbers are a mix of "+447..." and "447...",
     * depending on the imported CSV. A report formatted differently again
     * ("+44-7700-900123", "00447700900123") must still match the row we sent, so
     * both sides are reduced to digits and any international prefix is stripped.
     */
    public static function recipient(array $params): string
    {
        return self::canonical(self::value($params, self::RECIPIENT_KEYS));
    }

    /**
     * Digits-only comparison key. Used on both sides of a delivery-report match
     * so a stored "+447700900123" and a reported "00447700900123" line up.
     */
    public static function canonical(?string $phone): string
    {
        if ($phone === null || trim($phone) === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        // 00 international prefix is the same number as the bare form.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    /**
     * First non-empty value among the given keys, case-insensitively — a report
     * arriving as MESSAGE_ID must read the same as message_id.
     */
    private static function value(array $params, array $keys): string
    {
        $lower = [];
        foreach ($params as $k => $v) {
            if (is_scalar($v)) {
                $lower[strtolower((string) $k)] = trim((string) $v);
            }
        }

        foreach ($keys as $key) {
            $k = strtolower($key);
            if (isset($lower[$k]) && $lower[$k] !== '') {
                return $lower[$k];
            }
        }

        return '';
    }
}
