<?php

namespace JanDev\EmailSystem\Support\Sms;

use Throwable;

/**
 * Config reads that cannot throw.
 *
 * Laravel's config() resolves through the container, which is not always booted
 * where this code runs: unit tests instantiate these classes directly, and a
 * queue worker can call them while the app is tearing down. A missing config key
 * should mean "not configured" and take the safe branch, never a fatal in the
 * middle of a send.
 */
final class SmsConfig
{
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = config($key, $default);
        } catch (Throwable) {
            return $default;
        }

        return $value ?? $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
