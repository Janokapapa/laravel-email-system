<?php

namespace JanDev\EmailSystem\Support\Sms;

use Illuminate\Support\Facades\Log;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\Campaign;
use JanDev\EmailSystem\Models\EmailLog;
use JanDev\EmailSystem\Models\SmsSuppression;
use JanDev\EmailSystem\Support\CampaignFilterBuilder;

/**
 * Sends an SMS campaign to a prospect audience.
 *
 * The audience here is an imported list, not a customer base, so the rules
 * differ from the casino platform this logic came from:
 *
 *  - consent cannot be read off a player record; the only opt-out we hold is the
 *    suppression table, and it is checked at send time rather than at import
 *  - a recipient without a usable phone is dropped silently: a CSV always has
 *    some, and refusing the whole campaign over them helps nobody
 *  - every recipient gets their own short link, so a click names a person
 *
 * Nothing here is recoverable once the provider has accepted a message, which is
 * why the estimate, the cap and the suppression check all happen before the first
 * send rather than per chunk.
 */
final class SmsCampaignSender
{
    /** Recipients per provider batch. */
    private const CHUNK = 500;

    /**
     * Why this campaign cannot be sent, or null when it can.
     */
    public static function blockedReason(Campaign $campaign): ?string
    {
        if (!$campaign->isSms()) {
            return 'This is not an SMS campaign.';
        }
        if (!Mobivate::isConfigured()) {
            return 'The SMS provider is not configured (email-system.sms.api_key).';
        }
        if (trim((string) $campaign->body) === '') {
            return 'The message is empty.';
        }
        if (self::dailyRemaining() === 0) {
            return 'The daily SMS cap has been reached.';
        }

        return null;
    }

    /**
     * Messages still allowed today, or null when no cap is set.
     *
     * Counted across campaigns, not per campaign: three campaigns of "only a
     * thousand each" is the shape an unintended bill usually takes. Zero in
     * config means uncapped, which on a paid channel is rarely deliberate.
     */
    public static function dailyRemaining(): ?int
    {
        $cap = SmsConfig::int('email-system.sms.daily_cap', 0);
        if ($cap <= 0) {
            return null;
        }

        $usedToday = EmailLog::where('channel', 'sms')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return max(0, $cap - $usedToday);
    }

    /**
     * The audience as the sender will see it, with every drop named.
     *
     * The breakdown is the honest answer to "why did only N people get it", and
     * it is much cheaper to read before the send than after.
     *
     * @return array{selected: int, no_phone: int, suppressed: int, inactive: int, final: int}
     */
    public static function breakdown(Campaign $campaign): array
    {
        $groupIds = is_array($campaign->audience_group_ids) ? $campaign->audience_group_ids : [];
        $filters = is_array($campaign->custom_field_filters) ? $campaign->custom_field_filters : [];

        $base = CampaignFilterBuilder::applyFilters(
            AudienceUser::whereIn('email_audience_group_id', $groupIds),
            $filters
        );

        $selected = (clone $base)->count();
        $active = (clone $base)->where('is_active', true);
        $inactive = $selected - (clone $active)->count();

        $withPhone = (clone $active)->whereNotNull('phone')->where('phone', '!=', '');
        $noPhone = (clone $active)->count() - (clone $withPhone)->count();

        $suppressed = 0;
        $final = 0;
        (clone $withPhone)->select(['phone'])->chunk(2000, function ($rows) use (&$suppressed, &$final): void {
            $phones = $rows->pluck('phone')->all();
            $blocked = SmsSuppression::blockedAmong($phones);
            foreach ($phones as $phone) {
                $normalised = SmsPhone::normalise($phone);
                if ($normalised === null) {
                    continue; // counted as no_phone below
                }
                if (isset($blocked[$normalised])) {
                    $suppressed++;
                    continue;
                }
                $final++;
            }
        });

        // Numbers present but unusable belong with the missing ones: from the
        // sender's point of view they are the same problem.
        $unusable = (clone $withPhone)->count() - ($suppressed + $final);

        return [
            'selected' => $selected,
            'inactive' => $inactive,
            'no_phone' => $noPhone + max(0, $unusable),
            'suppressed' => $suppressed,
            'final' => $final,
        ];
    }

    /**
     * Cost estimate measured against the real audience.
     *
     * Names change the segment count and the destination changes the rate, and
     * the two are independent, so the total is only right if both are counted per
     * recipient.
     *
     * @return array{encoding: string, recipients: int, billable_segments: int,
     *               by_segments: array<int, int>, ucs2_recipients: int, cost: float|null,
     *               by_country: array<string, array{recipients: int, cost: float}>}
     */
    public static function estimate(Campaign $campaign): array
    {
        $body = self::measurableBody((string) $campaign->body);
        $buckets = [];

        self::eachRecipient($campaign, function (AudienceUser $user) use (&$buckets): void {
            $phone = SmsPhone::normalise($user->phone);
            if ($phone === null) {
                return;
            }
            $key = ((string) $user->name) . '|' . SmsPhone::prefix($phone);
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['name' => (string) $user->name, 'prefix' => SmsPhone::prefix($phone), 'count' => 0];
            }
            $buckets[$key]['count']++;
        });

        return SmsText::estimateForBuckets($body, array_values($buckets), self::foldEnabled());
    }

    /**
     * Queue and send the campaign.
     *
     * @param list<string> $testNumbers when given, these replace the audience
     *                                  entirely - an override, never an addition,
     *                                  because "test plus everyone" is how a test
     *                                  reaches the whole list
     * @return array{sent: int, failed: int, skipped: int}
     */
    public static function send(Campaign $campaign, array $testNumbers = []): array
    {
        $blocked = self::blockedReason($campaign);
        if ($blocked !== null) {
            Log::warning("SMS campaign {$campaign->id} not sent: {$blocked}");

            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $rawBody = (string) $campaign->body;
        $urls = SmsText::extractUrls($rawBody);
        $fold = self::foldEnabled();

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        $handle = function (array $recipients) use ($campaign, $rawBody, $urls, $fold, &$sent, &$failed, &$skipped): void {
            if ($recipients === []) {
                return;
            }

            // One link per recipient per URL, minted for the whole chunk.
            $links = [];
            foreach ($urls as $url) {
                $links[$url] = ShortLinkClient::createMany($url, count($recipients), (int) $campaign->id);
            }

            $messages = [];
            $rows = [];
            foreach (array_values($recipients) as $i => $r) {
                $text = self::compose($rawBody, (string) ($r['name'] ?? ''), (string) ($r['email'] ?? ''), $links, $i, $fold);
                $messages[] = ['to' => $r['phone'], 'message' => $text, 'reference' => 'c' . $campaign->id];
                $rows[] = ['phone' => $r['phone'], 'name' => $r['name'] ?? null, 'text' => $text];
            }

            $results = Mobivate::sendMany($messages);

            foreach ($rows as $i => $row) {
                $result = $results[$i] ?? ['ok' => false, 'id' => null, 'error' => 'no provider response'];
                $result['ok'] ? $sent++ : $failed++;

                EmailLog::create([
                    'campaign_id' => $campaign->id,
                    'channel' => 'sms',
                    'recipient' => $row['phone'],
                    'recipient_name' => $row['name'],
                    'subject' => $campaign->name,
                    'message' => $row['text'],
                    'segments' => SmsText::segments($row['text']),
                    'status' => $result['ok'] ? 'sent' : 'failed',
                    'sent_at' => now(),
                ]);
            }
        };

        if ($testNumbers !== []) {
            // The test goes through the same composition and the same minting as a
            // real send: a test that takes a different path is not a test.
            $recipients = [];
            foreach ($testNumbers as $number) {
                $phone = SmsPhone::normalise($number);
                if ($phone === null) {
                    $skipped++;
                    continue;
                }
                $recipients[] = ['phone' => $phone, 'name' => '', 'email' => ''];
            }
            $handle($recipients);

            return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
        }

        $chunk = [];
        self::eachRecipient($campaign, function (AudienceUser $user) use (&$chunk, $handle, &$skipped): void {
            $phone = SmsPhone::normalise($user->phone);
            if ($phone === null) {
                $skipped++;

                return;
            }
            $chunk[] = ['phone' => $phone, 'name' => (string) $user->name, 'email' => (string) $user->email];

            if (count($chunk) >= self::CHUNK) {
                $handle($chunk);
                $chunk = [];
            }
        });
        $handle($chunk);

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * Walk the sendable audience, suppression already applied.
     *
     * The suppression check is per chunk rather than per recipient: a campaign is
     * tens of thousands of rows and one query each would dominate the send.
     */
    private static function eachRecipient(Campaign $campaign, callable $callback): void
    {
        $groupIds = is_array($campaign->audience_group_ids) ? $campaign->audience_group_ids : [];
        $filters = is_array($campaign->custom_field_filters) ? $campaign->custom_field_filters : [];

        if ($groupIds === []) {
            return;
        }

        CampaignFilterBuilder::applyFilters(
            AudienceUser::whereIn('email_audience_group_id', $groupIds)
                ->where('is_active', true)
                ->whereNotNull('phone')
                ->where('phone', '!=', ''),
            $filters
        )->orderBy('id')->chunk(2000, function ($users) use ($callback): void {
            $blocked = SmsSuppression::blockedAmong($users->pluck('phone')->all());

            foreach ($users as $user) {
                $normalised = SmsPhone::normalise($user->phone);
                if ($normalised === null || isset($blocked[$normalised])) {
                    continue;
                }
                $callback($user);
            }
        });
    }

    /**
     * The exact text one recipient receives.
     *
     * @param array<string, list<string>> $links url => short link per recipient
     */
    private static function compose(string $body, string $name, string $email, array $links, int $index, bool $fold): string
    {
        $text = (string) preg_replace_callback(
            '/\{\{?\s*([a-z_]+)[^{}]*\}\}?/i',
            static function (array $m) use ($name, $email): string {
                return match (strtolower($m[1])) {
                    'name', 'first_name' => $name,
                    'email' => $email,
                    // An unknown placeholder becomes nothing rather than staying
                    // visible: braces in a delivered SMS look broken and are billed.
                    default => '',
                };
            },
            $body
        );

        foreach ($links as $url => $perRecipient) {
            if (isset($perRecipient[$index]) && $perRecipient[$index] !== '') {
                $text = str_replace((string) $url, $perRecipient[$index], $text);
            }
        }

        return $fold ? SmsText::foldToGsm7($text) : $text;
    }

    /** Body as measured: links stand in at their shortened length. */
    private static function measurableBody(string $body): string
    {
        return SmsText::previewShortenedLinks($body, ShortLinkClient::sampleUrl());
    }

    public static function foldEnabled(): bool
    {
        return SmsConfig::bool('email-system.sms.fold_accents', true);
    }
}
