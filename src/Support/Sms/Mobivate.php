<?php

namespace JanDev\EmailSystem\Support\Sms;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Mobivate (Vortex) SMS provider.
 *
 * Sends are batched through a Guzzle pool rather than looped: measured on the
 * casino platform, 24 messages took 1.2 seconds pooled against 7.2 sequential,
 * and a campaign is tens of thousands.
 *
 * The provider's own URL shortener is deliberately left off. We shorten links
 * ourselves, one per recipient, because that is what makes a click attributable
 * to a person - and their shortener is billed per link on top of the message.
 */
final class Mobivate
{
    private const URL = 'https://vortex.mobivatebulksms.com/send/single';
    private const TIMEOUT = 15;

    /** Concurrent requests. Enough to be fast, not enough to look like an attack. */
    private const CONCURRENCY = 12;

    /**
     * Send one message.
     *
     * @return array{ok: bool, id: string|null, error: string|null}
     */
    public static function send(string $to, string $message, ?string $reference = null): array
    {
        $auth = self::auth();
        if ($auth === null) {
            return ['ok' => false, 'id' => null, 'error' => 'Mobivate credentials are not configured'];
        }

        try {
            $client = new Client(['timeout' => self::TIMEOUT]);
            $response = $client->post(self::url(), [
                'headers' => ['Authorization' => $auth, 'Content-Type' => 'application/json'],
                'json' => self::payload($to, $message, $reference),
                'http_errors' => false,
            ]);

            return self::interpret((string) $response->getBody());
        } catch (Throwable $e) {
            return ['ok' => false, 'id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send many messages concurrently.
     *
     * @param list<array{to: string, message: string, reference?: string|null}> $messages
     * @return list<array{ok: bool, id: string|null, error: string|null}> aligned with $messages
     */
    public static function sendMany(array $messages, ?int $concurrency = null): array
    {
        if ($messages === []) {
            return [];
        }

        $auth = self::auth();
        if ($auth === null) {
            $failure = ['ok' => false, 'id' => null, 'error' => 'Mobivate credentials are not configured'];

            return array_fill(0, count($messages), $failure);
        }

        $client = new Client(['timeout' => self::TIMEOUT]);
        $url = self::url();
        $results = [];

        $requests = function () use ($messages, $auth, $url): \Generator {
            foreach ($messages as $i => $m) {
                yield $i => new Request(
                    'POST',
                    $url,
                    ['Authorization' => $auth, 'Content-Type' => 'application/json'],
                    (string) json_encode(self::payload($m['to'], $m['message'], $m['reference'] ?? null))
                );
            }
        };

        (new Pool($client, $requests(), [
            'concurrency' => $concurrency ?? self::CONCURRENCY,
            'fulfilled' => function (ResponseInterface $response, int $index) use (&$results): void {
                $results[$index] = self::interpret((string) $response->getBody());
            },
            'rejected' => function (mixed $reason, int $index) use (&$results): void {
                $results[$index] = [
                    'ok' => false,
                    'id' => null,
                    'error' => $reason instanceof Throwable ? $reason->getMessage() : 'request rejected',
                ];
            },
        ]))->promise()->wait();

        ksort($results);

        return array_values($results);
    }

    public static function isConfigured(): bool
    {
        return self::auth() !== null;
    }

    /**
     * The sender the recipient sees, by destination country.
     *
     * Some countries only deliver registered sender names, and cold traffic
     * should not share a sender with password resets - a complaint against the
     * marketing sender must not take the transactional one down with it.
     */
    public static function originatorFor(string $phone): string
    {
        $default = SmsConfig::string('email-system.sms.originator', 'Info') ?: 'Info';
        $map = SmsConfig::get('email-system.sms.originators');
        if (is_string($map)) {
            $decoded = json_decode($map, true);
            $map = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($map) || $map === []) {
            return $default;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        $best = '';
        foreach ($map as $prefix => $originator) {
            $prefix = (string) $prefix;
            if ($prefix !== '' && str_starts_with($digits, $prefix) && strlen($prefix) > strlen($best)) {
                $best = $prefix;
            }
        }

        return $best !== '' ? (string) $map[$best] : $default;
    }

    /** @return array<string, mixed> */
    private static function payload(string $to, string $message, ?string $reference): array
    {
        $clean = SmsPhone::normalise($to) ?? $to;

        return [
            'originator' => self::originatorFor($clean),
            'recipient' => ltrim($clean, '+'),
            'body' => $message,
            'routeId' => SmsConfig::string('email-system.sms.route_id'),
            'reference' => $reference,
            // We mint one short link per recipient; theirs would replace ours,
            // cost extra per link, and take the per-person click tracking with it.
            'shortenUrls' => false,
        ];
    }

    private static function url(): string
    {
        return SmsConfig::string('email-system.sms.url') ?: self::URL;
    }

    private static function auth(): ?string
    {
        $key = SmsConfig::string('email-system.sms.api_key');

        return $key === '' ? null : $key;
    }

    /**
     * @return array{ok: bool, id: string|null, error: string|null}
     */
    private static function interpret(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'id' => null, 'error' => 'unreadable provider response'];
        }

        // The provider reports acceptance, not delivery. "ok" here means the
        // message was taken, and it is billed from that moment; whether it
        // reached a handset is only knowable from a delivery report.
        $status = strtoupper((string) ($data['statusCode'] ?? $data['status'] ?? ''));
        $accepted = in_array($status, ['0', 'OK', 'SUCCESS', 'ACCEPTED'], true);

        if (!$accepted) {
            Log::warning('Mobivate rejected a message', ['response' => $data]);
        }

        return [
            'ok' => $accepted,
            'id' => isset($data['id']) ? (string) $data['id'] : null,
            'error' => $accepted ? null : (string) ($data['errorMessage'] ?? $data['error'] ?? $status ?: 'rejected'),
        ];
    }
}
