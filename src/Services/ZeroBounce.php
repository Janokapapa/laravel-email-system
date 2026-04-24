<?php

namespace JanDev\EmailSystem\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Support\CampaignFilterBuilder;

/**
 * ZeroBounce Email Validation Service
 *
 * Validates email addresses using the ZeroBounce API v2.
 * API key is stored in config('email-system.zerobounce.api_key').
 *
 * Status mapping:
 * - valid      → valid (deliverable)
 * - catch-all  → catch_all (accepts all emails)
 * - abuse      → catch_all (treated as sendable; stored with sub_status='abuse')
 * - unknown    → unknown (couldn't determine)
 * - invalid, spamtrap, do_not_mail → invalid (do not send)
 * - everything else → unverified
 */
class ZeroBounce
{
    private const API_URL       = 'https://api.zerobounce.net/v2/validate';
    private const BATCH_API_URL = 'https://api.zerobounce.net/v2/validatebatch';
    private const CREDITS_URL   = 'https://api.zerobounce.net/v2/getcredits';

    public const BATCH_SIZE = 200;

    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_VALID      = 'valid';
    public const STATUS_CATCH_ALL  = 'catch_all';
    public const STATUS_UNKNOWN    = 'unknown';
    public const STATUS_INVALID    = 'invalid';
    public const STATUS_BOUNCED    = 'bounced';

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
     * Validate a batch of email addresses via ZeroBounce Batch API v2.
     * Max 200 emails per call. Returns results keyed by email address.
     *
     * @param  string[]    $emails  Array of email addresses (max 200)
     * @param  Client|null $client  Guzzle client (injectable for testing)
     * @return array<string, array{status: string, sub_status: string|null}>|null  Returns null on API failure
     */
    public static function validateBatch(array $emails, ?Client $client = null): ?array
    {
        if (empty($emails)) {
            return [];
        }

        $apiKey = config('email-system.zerobounce.api_key');
        if (!$apiKey) {
            Log::channel('queue')->warning('ZeroBounce: API key not configured');
            return null;
        }

        $client = $client ?? self::buildClient(90, 10);

        $emailBatch = array_map(fn (string $email) => [
            'email_address' => $email,
            'ip_address'    => '',
        ], array_values($emails));

        try {
            $response = $client->post(self::BATCH_API_URL, [
                'json' => [
                    'api_key'     => $apiKey,
                    'email_batch' => $emailBatch,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!empty($body['errors'])) {
                Log::channel('queue')->error('ZeroBounce batch: API errors: ' . json_encode($body['errors']));
                return null;
            }

            if (!isset($body['email_batch']) || !is_array($body['email_batch'])) {
                Log::channel('queue')->error('ZeroBounce batch: Invalid response structure');
                return null;
            }

            $results = [];
            foreach ($body['email_batch'] as $item) {
                $email          = $item['address'] ?? '';
                $originalStatus = strtolower($item['status'] ?? '');
                $status         = self::mapStatus($originalStatus);
                $rawSubStatus   = $item['sub_status'] ?? '';
                $subStatus      = (!empty($rawSubStatus)) ? $rawSubStatus : null;

                if ($subStatus === null && $originalStatus !== $status) {
                    $subStatus = $originalStatus;
                }

                $results[$email] = [
                    'status'     => $status,
                    'sub_status' => $subStatus,
                ];
            }

            Log::channel('queue')->info('ZeroBounce batch: Validated ' . count($results) . ' emails');

            return $results;

        } catch (\Throwable $e) {
            Log::channel('queue')->error('ZeroBounce batch: API error: ' . $e->getMessage());
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
            'abuse'                                          => self::STATUS_VALID,
            'invalid', 'spamtrap', 'do_not_mail'              => self::STATUS_INVALID,
            default                                      => self::STATUS_UNVERIFIED,
        };
    }

    /**
     * Verify all unverified emails in an audience group via the ZeroBounce API.
     *
     * Steps performed:
     *  1. Normalize any NULL zerobounce_status rows to 'unverified' so the existing
     *     query (WHERE zerobounce_status = 'unverified') picks them up.
     *  2. Chunk through unverified, active, non-bounced users (with optional custom-
     *     field filters) and call validate() on each email address.
     *  3. Update zerobounce_status / sub_status / checked_at on success.
     *  4. Abort after 10 consecutive API errors to handle API outages gracefully.
     *
     * @param  int           $groupId            Audience group to verify
     * @param  array         $customFieldFilters  Campaign-level custom field filters
     * @param  callable|null $validator           Injectable for testing (default: ZeroBounce::validate)
     * @return array{verified: int, skipped: int, errors: int}
     */
    public static function verifyAudienceGroup(
        int $groupId,
        array $customFieldFilters = [],
        ?callable $validator = null,
    ): array {
        $batchValidate = $validator ?? fn (array $emails) => self::validateBatch($emails);

        // Normalize NULL statuses so the chunk query below picks them up
        DB::table('audience_users')
            ->where('email_audience_group_id', $groupId)
            ->whereNull('zerobounce_status')
            ->where('is_active', true)
            ->where('bounced', false)
            ->update(['zerobounce_status' => self::STATUS_UNVERIFIED]);

        $verified          = 0;
        $skipped           = 0;
        $errors            = 0;
        $consecutiveErrors = 0;
        $aborted           = false;

        $query = AudienceUser::where('email_audience_group_id', $groupId)
            ->where('zerobounce_status', self::STATUS_UNVERIFIED)
            ->where('is_active', true)
            ->where('bounced', false);

        CampaignFilterBuilder::applyFilters($query, $customFieldFilters);

        $query->chunkById(self::BATCH_SIZE, function ($users) use (
            &$verified, &$skipped, &$errors, &$consecutiveErrors, &$aborted,
            $groupId, $batchValidate
        ) {
            if ($aborted) {
                return false;
            }

            $emails = $users->pluck('email')->toArray();
            $results = $batchValidate($emails);

            if ($results === null) {
                $errors += count($emails);
                $skipped += count($emails);
                $consecutiveErrors++;

                if ($consecutiveErrors >= 3) {
                    Log::channel('queue')->error(
                        "ZeroBounce::verifyAudienceGroup: Aborting — 3 consecutive batch failures. GroupId={$groupId}"
                    );
                    $aborted = true;
                    return false;
                }

                return;
            }

            $consecutiveErrors = 0;
            $now = Carbon::now();

            foreach ($users as $user) {
                $result = $results[$user->email] ?? null;

                if ($result === null) {
                    $skipped++;
                    continue;
                }

                $user->update([
                    'zerobounce_status'     => $result['status'],
                    'zerobounce_sub_status' => $result['sub_status'],
                    'zerobounce_checked_at' => $now,
                ]);

                $verified++;
            }
        });

        return ['verified' => $verified, 'skipped' => $skipped, 'errors' => $errors];
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
            self::STATUS_BOUNCED    => 'Bounced',
            self::STATUS_UNVERIFIED => 'Unverified',
            default                 => ucfirst($status),
        };
    }

    /**
     * Get a human-readable label combining status and sub-status when present.
     * Example: status='invalid', sub='mailbox_not_found' → "Invalid (mailbox_not_found)".
     * Falls back to plain label when sub-status is empty.
     */
    public static function getStatusLabelWithSubStatus(string $status, ?string $subStatus): string
    {
        $label = self::getStatusLabel($status);
        if ($subStatus !== null && $subStatus !== '') {
            return $label . ' (' . $subStatus . ')';
        }
        return $label;
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
            self::STATUS_BOUNCED    => 'danger',
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
