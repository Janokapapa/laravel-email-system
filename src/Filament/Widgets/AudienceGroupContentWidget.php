<?php

namespace JanDev\EmailSystem\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use JanDev\EmailSystem\Support\Sms\SmsPhone;
use JanDev\EmailSystem\Support\Sms\SmsPricing;

/**
 * What a list actually holds.
 *
 * Lists arrive as CSV files named after whoever exported them, and a month
 * later nobody can tell a UK number file from an address file without opening
 * it. The two questions asked in practice are "can I text this?" and "where are
 * these people?" - the second one decides the bill, since a segment to Spain
 * costs twice what one to the UK does.
 *
 * Countries come from the dialling code of the stored number, not from a column
 * someone filled in: the number is what gets dialled.
 */
class AudienceGroupContentWidget extends Widget
{
    protected string $view = 'email-system::widgets.audience-group-content';

    protected static bool $isLazy = false;

    protected int | string | array $columnSpan = 'full';

    public Model | int | string | null $record = null;

    /**
     * Grouping on a five-character prefix keeps this to a few hundred rows even
     * on a six-figure list, instead of reading every number into PHP.
     */
    public function getContent(): array
    {
        $groupId = $this->record instanceof Model ? $this->record->getKey() : $this->record;

        if (! $groupId) {
            return ['total' => 0, 'emails' => 0, 'numbers' => 0, 'unusable' => 0, 'countries' => []];
        }

        $totals = DB::table('audience_users')
            ->where('email_audience_group_id', $groupId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as emails")
            ->selectRaw('SUM(CASE WHEN phone REGEXP ? THEN 1 ELSE 0 END) as numbers', [SmsPhone::E164_SQL_REGEX])
            ->selectRaw("SUM(CASE WHEN phone IS NOT NULL AND phone != '' AND phone NOT REGEXP ? THEN 1 ELSE 0 END) as unusable", [SmsPhone::E164_SQL_REGEX])
            ->first();

        $prefixes = DB::table('audience_users')
            ->where('email_audience_group_id', $groupId)
            ->whereNotNull('phone')
            ->where('phone', 'REGEXP', SmsPhone::E164_SQL_REGEX)
            ->selectRaw('LEFT(phone, 5) as head, COUNT(*) as c')
            ->groupBy('head')
            ->get();

        $byCountry = [];
        foreach ($prefixes as $row) {
            $digits = ltrim((string) $row->head, '+');
            $dial = self::dialCodeOf($digits);
            $key = $dial ?? '?';
            $byCountry[$key] = ($byCountry[$key] ?? 0) + (int) $row->c;
        }

        arsort($byCountry);

        $countries = [];
        foreach ($byCountry as $dial => $count) {
            $countries[] = [
                'label' => $dial === '?' ? __('Unknown') : self::labelFor($dial),
                'dial' => $dial === '?' ? '' : '+' . $dial,
                'count' => $count,
                'price' => $dial === '?' ? null : SmsPricing::forPhone('+' . $dial),
            ];
        }

        return [
            'total' => (int) ($totals->total ?? 0),
            'emails' => (int) ($totals->emails ?? 0),
            'numbers' => (int) ($totals->numbers ?? 0),
            'unusable' => (int) ($totals->unusable ?? 0),
            'countries' => $countries,
        ];
    }

    /** Longest dialling code the digits start with, so +1 never shadows +44. */
    private static function dialCodeOf(string $digits): ?string
    {
        $best = null;
        foreach (SmsPhone::DIAL_CODES as $dial) {
            if (str_starts_with($digits, $dial) && (($best === null) || strlen($dial) > strlen($best))) {
                $best = $dial;
            }
        }

        return $best;
    }

    /**
     * Country name for a dialling code. Several codes are shared (+1 is the US
     * and Canada), so every country holding the code is named rather than
     * picking one and being wrong half the time.
     */
    private static function labelFor(string $dial): string
    {
        $names = \JanDev\EmailSystem\Support\CsvHelper::getCountryOptions();
        $matches = [];
        foreach (SmsPhone::DIAL_CODES as $iso => $code) {
            if ($code === $dial) {
                $matches[] = $names[$iso] ?? $iso;
            }
        }

        return $matches === [] ? __('Unknown') : implode(' / ', $matches);
    }
}
