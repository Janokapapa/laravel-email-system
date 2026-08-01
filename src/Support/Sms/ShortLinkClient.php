<?php

namespace JanDev\EmailSystem\Support\Sms;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-recipient short links from the shared 7so.io service.
 *
 * Not a new service: the shortener already runs on the chat server and is used by
 * the casino platform. This is the client for it, so a campaign sent from here
 * gets the same links and the same per-person click tracking.
 *
 * One link per recipient, for two reasons. A click can only name a person if the
 * link is theirs; and a single URL repeated across thousands of messages is a
 * spam signal, which on this channel means the later messages quietly stop
 * arriving while still being billed.
 */
final class ShortLinkClient
{
    private const TIMEOUT = 20;

    /** The bulk endpoint refuses more than this per call. */
    private const MAX_PER_CALL = 2000;

    /**
     * Mint one link per recipient for a URL.
     *
     * Returns an empty list on failure rather than throwing: a campaign whose
     * links could not be shortened should still go out with the full URL, which
     * works, instead of not going out at all.
     *
     * @return list<string> aligned with the recipients, or [] when unavailable
     */
    public static function createMany(string $url, int $count, ?int $campaignId = null): array
    {
        if ($count <= 0 || !self::isConfigured()) {
            return [];
        }
        if ($count > self::MAX_PER_CALL) {
            $out = [];
            foreach (array_chunk(range(0, $count - 1), self::MAX_PER_CALL) as $slice) {
                $part = self::createMany($url, count($slice), $campaignId);
                if ($part === []) {
                    return [];
                }
                $out = array_merge($out, $part);
            }

            return $out;
        }

        try {
            $client = new Client(['timeout' => self::TIMEOUT, 'force_ip_resolve' => 'v4']);
            $response = $client->post(rtrim(self::baseUrl(), '/') . '/campaign-link/create-bulk', [
                'headers' => ['X-Internal-Key' => self::internalKey(), 'Accept' => 'application/json'],
                'form_params' => [
                    'target_url' => $url,
                    'casino' => self::source(),
                    'campaign_id' => $campaignId,
                    // The service keys its answer by recipient id. Prospects have
                    // no id in the casino sense, so sequential positions are used;
                    // what matters is that each recipient gets a distinct link.
                    'user_ids' => implode(',', range(1, $count)),
                ],
                'http_errors' => false,
            ]);

            $data = json_decode((string) $response->getBody(), true);
            if (!is_array($data) || ($data['ok'] ?? false) !== true || !is_array($data['urls'] ?? null)) {
                Log::warning('ShortLinkClient: unexpected response, falling back to full URLs', ['body' => $data]);

                return [];
            }

            $out = [];
            foreach (range(1, $count) as $i) {
                $link = $data['urls'][(string) $i] ?? null;
                if (!is_string($link) || $link === '') {
                    return [];
                }
                $out[] = $link;
            }

            return $out;
        } catch (Throwable $e) {
            Log::warning('ShortLinkClient: mint failed, falling back to full URLs: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * A stand-in of the right length, for measuring a message before the real
     * links exist. Measuring the raw URL quotes a price the campaign will not cost.
     */
    public static function sampleUrl(): string
    {
        return rtrim(self::publicBase(), '/') . '/-xxxxxxx';
    }

    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '' && self::internalKey() !== '';
    }

    private static function baseUrl(): string
    {
        return SmsConfig::string('email-system.sms.shortlink.base_url');
    }

    private static function publicBase(): string
    {
        return SmsConfig::string('email-system.sms.shortlink.public_url', 'https://7so.io');
    }

    private static function internalKey(): string
    {
        return SmsConfig::string('email-system.sms.shortlink.key');
    }

    /** Which system minted the link, so clicks can be told apart at the service. */
    private static function source(): string
    {
        return SmsConfig::string('email-system.sms.shortlink.source', 'email-marketing');
    }
}
