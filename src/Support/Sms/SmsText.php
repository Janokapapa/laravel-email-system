<?php

namespace JanDev\EmailSystem\Support\Sms;

/**
 * SMS text measurement: encoding, segment count and cost estimate.
 *
 * Unlike email, every extra segment is real money, and the trigger is invisible
 * in the editor: a single accented character pushes the whole message from GSM-7
 * to UCS-2 and cuts the per-segment budget from 160 characters to 70. Campaign
 * copy pasted out of Word arrives with curly quotes and en dashes that look
 * identical on screen and cost twice as much.
 *
 * Ported from the casino platform, where this logic was measured against real
 * sends. The two implementations must stay in step, which is what the shared
 * fixture in tests/Unit/SmsTextParityTest.php enforces: it is generated from the
 * casino side and both codebases must reproduce it exactly.
 *
 * Pure functions: no DB, no provider call, so the campaign form and the sender
 * can both measure the same way.
 */
final class SmsText
{
    /** Per-segment character budget, single message vs concatenated parts. */
    private const GSM_SINGLE = 160;
    private const GSM_CONCAT = 153;
    private const UCS_SINGLE = 70;
    private const UCS_CONCAT = 67;

    /**
     * GSM 03.38 basic character set. Anything outside this (plus the extension
     * table below) forces UCS-2.
     */
    private const GSM_BASIC = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?'
        . '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /** Extension table: still GSM-7, but each one is billed as two characters. */
    private const GSM_EXTENDED = ['^', '{', '}', '\\', '[', '~', ']', '|', '€'];

    /**
     * Worst-case rendered width of template placeholders. The raw template
     * under-reports the real message: `{{name}}` is 8 characters here and can be a
     * 20-character name at send time. Estimating on the short side is the expensive
     * mistake, so unknown placeholders get a generous default.
     *
     * This app's placeholders differ from the casino platform's: audiences are
     * imported from CSV, so the name field is `name` and everything else is a
     * custom-field slug that cannot be enumerated here.
     */
    private const PLACEHOLDER_WIDTHS = [
        'name'        => 20,
        'first_name'  => 20,
        'last_name'   => 20,
        'email'       => 40,
        // An unsubscribe or campaign link is shortened at send time; this is the
        // width of the short URL, not of the target.
        'unsubscribe' => 40,
        'link'        => 40,
    ];
    private const PLACEHOLDER_DEFAULT_WIDTH = 20;

    /** Placeholders replaced with the recipient's real name in an exact estimate. */
    private const NAME_PLACEHOLDERS = ['name', 'first_name', 'last_name'];

    /**
     * Latin letters outside GSM-7 and their plain replacements.
     *
     * Deliberately partial: ä ö ü é è à ù ì ò ç ñ ø å æ ß are IN the GSM-7 table,
     * so folding them would damage German, French and Nordic copy without saving a
     * single segment. Only the letters that actually force UCS-2 are listed.
     */
    private const FOLD_MAP = [
        // Typography that word processors insert silently. This is the costly
        // invisible case: pasting campaign copy out of Word or Google Docs brings
        // curly quotes and en dashes with it, none of which are GSM-7, so a plain
        // English text triples in price for characters the author cannot see.
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{00AB}" => '"', "\u{00BB}" => '"',
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-', "\u{2010}" => '-', "\u{2011}" => '-',
        "\u{2026}" => '...', "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ', "\u{200B}" => '',
        "\u{2022}" => '*', "\u{00B7}" => '.', "\u{2122}" => 'TM', "\u{00AE}" => '(R)', "\u{00A9}" => '(C)',
        // Hungarian
        'ő' => 'o', 'Ő' => 'O', 'ű' => 'u', 'Ű' => 'U',
        // Czech / Slovak / Croatian / Slovenian / Serbian latin
        'č' => 'c', 'Č' => 'C', 'ć' => 'c', 'Ć' => 'C', 'ď' => 'd', 'Ď' => 'D',
        'ě' => 'e', 'Ě' => 'E', 'ľ' => 'l', 'Ľ' => 'L', 'ň' => 'n', 'Ň' => 'N',
        'ř' => 'r', 'Ř' => 'R', 'š' => 's', 'Š' => 'S', 'ť' => 't', 'Ť' => 'T',
        'ů' => 'u', 'Ů' => 'U', 'ž' => 'z', 'Ž' => 'Z', 'đ' => 'd', 'Đ' => 'D',
        // Polish
        'ą' => 'a', 'Ą' => 'A', 'ę' => 'e', 'Ę' => 'E', 'ł' => 'l', 'Ł' => 'L',
        'ń' => 'n', 'Ń' => 'N', 'ś' => 's', 'Ś' => 'S', 'ź' => 'z', 'Ź' => 'Z',
        'ż' => 'z', 'Ż' => 'Z', 'ó' => 'o', 'Ó' => 'O',
        // Turkish
        'ş' => 's', 'Ş' => 'S', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I',
        // Romanian
        'ă' => 'a', 'Ă' => 'A', 'â' => 'a', 'Â' => 'A', 'î' => 'i', 'Î' => 'I',
        'ș' => 's', 'Ș' => 'S', 'ț' => 't', 'Ț' => 'T',
        // Baltic / other common latin
        'ā' => 'a', 'Ā' => 'A', 'ē' => 'e', 'Ē' => 'E', 'ī' => 'i', 'Ī' => 'I',
        'ū' => 'u', 'Ū' => 'U', 'ģ' => 'g', 'Ģ' => 'G', 'ķ' => 'k', 'Ķ' => 'K',
        'ļ' => 'l', 'Ļ' => 'L', 'ņ' => 'n', 'Ņ' => 'N',
        'á' => 'a', 'Á' => 'A', 'í' => 'i', 'Í' => 'I', 'ú' => 'u', 'Ú' => 'U',
        'ý' => 'y', 'Ý' => 'Y', 'ê' => 'e', 'Ê' => 'E', 'ô' => 'o', 'Ô' => 'O',
        'û' => 'u', 'Û' => 'U', 'ã' => 'a', 'Ã' => 'A', 'õ' => 'o', 'Õ' => 'O',
    ];

    /**
     * URLs found in a message body.
     *
     * @return list<string>
     */
    public static function extractUrls(string $text): array
    {
        if (preg_match_all('~https?://[^\s<>"\']+~i', $text, $m) === false) {
            return [];
        }
        $urls = [];
        foreach ($m[0] as $url) {
            // Trailing punctuation is sentence, not URL.
            $url = rtrim($url, '.,;:!?)');
            if ($url !== '' && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Body as it will be sent once links are shortened, for measurement only:
     * every URL is swapped for a same-length stand-in. Measuring the raw body
     * over-reports the segments and quotes a price the campaign will not cost.
     */
    public static function previewShortenedLinks(string $text, string $sampleUrl): string
    {
        foreach (self::extractUrls($text) as $url) {
            if (str_contains($url, '/l/')) {
                continue;
            }
            $text = str_replace($url, $sampleUrl, $text);
        }

        return $text;
    }

    /**
     * Replace the Latin letters that force UCS-2 with their plain equivalents.
     *
     * This is the cheap fix for the accent penalty: a Hungarian campaign text goes
     * from three segments to one. Greek, Cyrillic and Arabic are left untouched -
     * there is no honest ASCII for them, so those campaigns stay UCS-2 and the form
     * says so instead of mangling the message.
     */
    public static function foldToGsm7(string $text): string
    {
        return strtr($text, self::FOLD_MAP);
    }

    /** 'GSM-7' when every character fits the GSM 03.38 tables, 'UCS-2' otherwise. */
    public static function encodingOf(string $text): string
    {
        foreach (self::chars($text) as $ch) {
            if (!self::isGsm($ch)) {
                return 'UCS-2';
            }
        }

        return 'GSM-7';
    }

    /**
     * Number of billable segments for the text as it will actually be sent
     * (placeholders resolved at their worst-case width).
     */
    public static function segments(string $text): int
    {
        return self::segmentsOfResolved(self::resolvePlaceholders($text));
    }

    /** Segment count for text whose placeholders are already substituted. */
    private static function segmentsOfResolved(string $resolved): int
    {
        $ucs2 = self::encodingOf($resolved) === 'UCS-2';

        $length = 0;
        foreach (self::chars($resolved) as $ch) {
            // In GSM-7 the extension characters occupy two septets each; in UCS-2
            // every code point is one unit, extended or not.
            $length += (!$ucs2 && in_array($ch, self::GSM_EXTENDED, true)) ? 2 : 1;
        }

        $single = $ucs2 ? self::UCS_SINGLE : self::GSM_SINGLE;
        $concat = $ucs2 ? self::UCS_CONCAT : self::GSM_CONCAT;

        if ($length <= $single) {
            return 1; // an empty body is still one segment, never zero cost
        }

        return (int) ceil($length / $concat);
    }

    /**
     * Cost estimate for a campaign.
     *
     * A null $unitPrice yields a null cost on purpose: "no price configured" must
     * be distinguishable from "costs nothing" in the campaign form, otherwise an
     * unconfigured price silently reads as a free campaign.
     *
     * @return array{encoding: string, segments: int, billable_segments: int, cost: float|null}
     */
    public static function estimate(string $text, int $recipients, ?float $unitPrice): array
    {
        $segments = self::segments($text);
        $billable = $segments * max(0, $recipients);

        return [
            'encoding' => self::encodingOf(self::resolvePlaceholders($text)),
            'segments' => $segments,
            'billable_segments' => $billable,
            'cost' => $unitPrice === null ? null : $billable * $unitPrice,
        ];
    }

    /**
     * Exact estimate for a real audience.
     *
     * The worst-case estimate above is only a fallback for when there is no
     * audience yet. Once the campaign has one, guessing is unnecessary: the
     * recipient names are known, so every message can be measured as it will
     * actually be sent. That matters in both directions - the worst case
     * over-charges a campaign whose recipients are all called Ann, and a
     * per-campaign encoding decision would mis-charge the handful whose name alone
     * forces UCS-2.
     *
     * The audience arrives as name => count (one GROUP BY, a few hundred distinct
     * names for several thousand recipients), so this stays cheap for a whole list.
     *
     * @param array<string, int> $nameCounts recipient name => how many people have it
     * @param bool $fold apply foldToGsm7() first, as the sender will
     * @return array{encoding: string, recipients: int, billable_segments: int,
     *               by_segments: array<int, int>, ucs2_recipients: int, cost: float|null}
     */
    public static function estimateForAudience(string $body, array $nameCounts, ?float $unitPrice, bool $fold = false): array
    {
        if ($fold) {
            $body = self::foldToGsm7($body);
        }
        $recipients = 0;
        $billable = 0;
        $ucs2Recipients = 0;
        /** @var array<int, int> $bySegments */
        $bySegments = [];

        foreach ($nameCounts as $name => $count) {
            $count = max(0, $count);
            if ($count === 0) {
                continue;
            }
            $message = self::resolvePlaceholders($body, $fold ? self::foldToGsm7((string) $name) : (string) $name);
            $segments = self::segmentsOfResolved($message);

            $recipients += $count;
            $billable += $segments * $count;
            $bySegments[$segments] = ($bySegments[$segments] ?? 0) + $count;
            if (self::encodingOf($message) === 'UCS-2') {
                $ucs2Recipients += $count;
            }
        }

        ksort($bySegments);

        return [
            'encoding' => self::audienceEncoding($recipients, $ucs2Recipients),
            'recipients' => $recipients,
            'billable_segments' => $billable,
            'by_segments' => $bySegments,
            'ucs2_recipients' => $ucs2Recipients,
            'cost' => $unitPrice === null ? null : $billable * $unitPrice,
        ];
    }

    /**
     * Exact estimate for an audience whose price varies by destination.
     *
     * Segments come from the name, the rate comes from the phone's country, and
     * the two are independent - so the total can only be right if both are known
     * per recipient. This is the estimate the campaign form shows and records.
     *
     * @param list<array{name: string, prefix: string, count: int}> $buckets
     * @return array{encoding: string, recipients: int, billable_segments: int,
     *               by_segments: array<int, int>, ucs2_recipients: int, cost: float|null,
     *               by_country: array<string, array{recipients: int, cost: float}>}
     */
    public static function estimateForBuckets(string $body, array $buckets, bool $fold = false): array
    {
        if ($fold) {
            $body = self::foldToGsm7($body);
        }

        $recipients = 0;
        $billable = 0;
        $ucs2Recipients = 0;
        $cost = 0.0;
        $priced = false;
        /** @var array<int, int> $bySegments */
        $bySegments = [];
        /** @var array<string, array{recipients: int, cost: float}> $byCountry */
        $byCountry = [];

        foreach ($buckets as $bucket) {
            $count = max(0, $bucket['count']);
            if ($count === 0) {
                continue;
            }
            $name = $fold ? self::foldToGsm7($bucket['name']) : $bucket['name'];
            $message = self::resolvePlaceholders($body, $name);
            $segments = self::segmentsOfResolved($message);

            $recipients += $count;
            $billable += $segments * $count;
            $bySegments[$segments] = ($bySegments[$segments] ?? 0) + $count;
            if (self::encodingOf($message) === 'UCS-2') {
                $ucs2Recipients += $count;
            }

            $price = SmsPricing::forPrefix($bucket['prefix']);
            if ($price !== null) {
                $priced = true;
                $bucketCost = $segments * $count * $price;
                $cost += $bucketCost;

                $key = $bucket['prefix'];
                $byCountry[$key] = [
                    'recipients' => ($byCountry[$key]['recipients'] ?? 0) + $count,
                    'cost' => ($byCountry[$key]['cost'] ?? 0.0) + $bucketCost,
                ];
            }
        }

        ksort($bySegments);
        uasort($byCountry, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        return [
            'encoding' => self::audienceEncoding($recipients, $ucs2Recipients),
            'recipients' => $recipients,
            'billable_segments' => $billable,
            'by_segments' => $bySegments,
            'ucs2_recipients' => $ucs2Recipients,
            'cost' => $priced ? $cost : null,
            'by_country' => $byCountry,
        ];
    }

    private static function audienceEncoding(int $recipients, int $ucs2Recipients): string
    {
        if ($recipients === 0 || $ucs2Recipients === 0) {
            return 'GSM-7';
        }

        return $ucs2Recipients === $recipients ? 'UCS-2' : 'mixed';
    }

    /**
     * Replace `{{placeholder}}` tokens with filler of their worst-case width, so
     * the measurement reflects the message the recipient receives, not the template.
     *
     * With a $name given, the name placeholders are filled with the real value
     * instead - the same substitution the sender performs, including the empty
     * string for recipients who have no name on file.
     */
    private static function resolvePlaceholders(string $text, ?string $name = null): string
    {
        return (string) preg_replace_callback(
            // Both brace styles, and the `{{unsubscribe=Text}}` form: matching only
            // the bare style leaves stray braces in the measurement and in the
            // delivered message.
            '/\{\{?\s*([a-z_]+)[^{}]*\}\}?/i',
            static function (array $m) use ($name): string {
                $key = strtolower($m[1]);
                if ($name !== null && in_array($key, self::NAME_PLACEHOLDERS, true)) {
                    return $name;
                }
                $width = self::PLACEHOLDER_WIDTHS[$key] ?? self::PLACEHOLDER_DEFAULT_WIDTH;

                // Filler must stay inside GSM-7 so a placeholder never invents a
                // UCS-2 switch that the real value would not cause.
                return str_repeat('x', $width);
            },
            $text
        );
    }

    /** @return list<string> */
    private static function chars(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $chars === false ? [] : $chars;
    }

    private static function isGsm(string $ch): bool
    {
        return in_array($ch, self::GSM_EXTENDED, true)
            || mb_strpos(self::GSM_BASIC, $ch) !== false;
    }
}
