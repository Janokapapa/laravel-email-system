<?php

namespace JanDev\EmailSystem\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * ZeroBounce Email Validation Service
 *
 * Validates email addresses using the ZeroBounce API v2.
 * API key is stored in config('email-system.zerobounce.api_key').
 *
 * Status mapping:
 * - valid      → valid (deliverable)
 * - catch-all  → catch_all (accepts all emails)
 * - unknown    → unknown (couldn't determine)
 * - invalid, spamtrap, abuse, do_not_mail → invalid (do not send)
 * - everything else → unverified
 */
class ZeroBounce
{
    private const API_URL     = 'https://api.zerobounce.net/v2/validate';
    private const CREDITS_URL = 'https://api.zerobounce.net/v2/getcredits';

    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_VALID      = 'valid';
    public const STATUS_CATCH_ALL  = 'catch_all';
    public const STATUS_UNKNOWN    = 'unknown';
    public const STATUS_INVALID    = 'invalid';

    /**
     * Check if ZeroBounce verification is enabled.
     * Requires both api_key and enabled=true in config.
     */
    public static function isEnabled(): bool
    {
        return !empty(config('email-system.zerobounce.api_key'))
            && (bool) config('email-system.zerobounce.enabled', false);
    }

    /**
     * Validate an email address via ZeroBounce API v2.
     *
     * @param  string      $email  Email address to validate
     * @param  Client|null $client Guzzle client (injectable for testing)
     * @return array{status: string, sub_status: string|null}|null Returns null on API failure
     */
    public static function validate(string $email, ?Client $client = null): ?array
    {
        $apiKey = config('email-system.zerobounce.api_key');
        if (!$apiKey) {
            Log::channel('queue')->warning('ZeroBounce: API key not configured');
            return null;
        }

        $client = $client ?? self::buildClient();

        try {
            $response = $client->get(self::API_URL, [
                'query' => [
                    'api_key'    => $apiKey,
                    'email'      => $email,
                    'ip_address' => '',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!isset($body['status'])) {
                Log::channel('queue')->error('ZeroBounce: Invalid API response for ' . $email . ': ' . json_encode($body));
                return null;
            }

            $originalStatus = strtolower($body['status']);
            $status         = self::mapStatus($originalStatus);
            $rawSubStatus   = $body['sub_status'] ?? '';
            $subStatus      = (!empty($rawSubStatus)) ? $rawSubStatus : null;

            // If original status was remapped (e.g. spamtrap → invalid), store original as sub_status
            if ($subStatus === null && $originalStatus !== $status) {
                $subStatus = $originalStatus;
            }

            Log::channel('queue')->info('ZeroBounce: ' . $email . ' → ' . $status . ($subStatus ? ' (' . $subStatus . ')' : ''));

            return [
                'status'     => $status,
                'sub_status' => $subStatus,
            ];

        } catch (\Throwable $e) {
            Log::channel('queue')->error('ZeroBounce: API error for ' . $email . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the current API credit balance.
     *
     * @param  Client|null $client Guzzle client (injectable for testing)
     * @return int|null Credit balance, or null on failure
     */
    public static function getCredits(?Client $client = null): ?int
    {
        $apiKey = config('email-system.zerobounce.api_key');
        if (!$apiKey) {
            return null;
        }

        $client = $client ?? self::buildClient(10, 5);

        try {
            $response = $client->get(self::CREDITS_URL, [
                'query' => ['api_key' => $apiKey],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['Credits'])) {
                return (int) $body['Credits'];
            }

            Log::channel('queue')->error('ZeroBounce: Invalid credits response: ' . json_encode($body));
            return null;

        } catch (\Throwable $e) {
            Log::channel('queue')->error('ZeroBounce: Credits API error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Map a ZeroBounce API status string to an internal status constant.
     */
    public static function mapStatus(string $apiStatus): string
    {
        return match (strtolower($apiStatus)) {
            'valid'                                      => self::STATUS_VALID,
            'catch-all'                                  => self::STATUS_CATCH_ALL,
            'unknown'                                    => self::STATUS_UNKNOWN,
            'invalid', 'spamtrap', 'abuse', 'do_not_mail' => self::STATUS_INVALID,
            default                                      => self::STATUS_UNVERIFIED,
        };
    }

    /**
     * Get a human-readable label for a status value.
     */
    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_VALID      => 'Valid',
            self::STATUS_CATCH_ALL  => 'Catch-All',
            self::STATUS_UNKNOWN    => 'Unknown',
            self::STATUS_INVALID    => 'Invalid',
            self::STATUS_UNVERIFIED => 'Unverified',
            default                 => ucfirst($status),
        };
    }

    /**
     * Get the Filament badge color for a status value.
     *
     * Valid    → success (green)
     * CatchAll → warning (yellow)
     * Unknown  → gray
     * Invalid  → danger (red)
     * Unverified → info (blue)
     */
    public static function getStatusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_VALID      => 'success',
            self::STATUS_CATCH_ALL  => 'warning',
            self::STATUS_UNKNOWN    => 'gray',
            self::STATUS_INVALID    => 'danger',
            self::STATUS_UNVERIFIED => 'info',
            default                 => 'gray',
        };
    }

    /**
     * Build a Guzzle HTTP client with the specified timeouts.
     */
    private static function buildClient(int $timeout = 10, int $connectTimeout = 5): Client
    {
        return new Client([
            'timeout'         => $timeout,
            'connect_timeout' => $connectTimeout,
        ]);
    }
}
