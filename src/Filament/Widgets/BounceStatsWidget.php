<?php

namespace JanDev\EmailSystem\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use JanDev\EmailSystem\Models\BouncedEmail;

class BounceStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $total = BouncedEmail::count();

        $codeCounts = BouncedEmail::query()
            ->selectRaw("
                CASE
                    WHEN bounce_reason REGEXP '^[0-9]\\\\.[0-9]+\\\\.[0-9]+' THEN REGEXP_SUBSTR(bounce_reason, '^[0-9]\\\\.[0-9]+\\\\.[0-9]+')
                    WHEN bounce_reason REGEXP '[0-9]\\\\.[0-9]+\\\\.[0-9]+' THEN REGEXP_SUBSTR(bounce_reason, '[0-9]\\\\.[0-9]+\\\\.[0-9]+')
                    ELSE 'unknown'
                END as bounce_code,
                COUNT(*) as cnt
            ")
            ->groupBy('bounce_code')
            ->orderByDesc('cnt')
            ->get();

        $stats = [
            Stat::make(__('Total Bounces'), number_format($total))
                ->icon('heroicon-o-no-symbol')
                ->color('danger'),
        ];

        foreach ($codeCounts as $row) {
            $color = match (true) {
                str_starts_with($row->bounce_code, '5.1.') => 'danger',
                str_starts_with($row->bounce_code, '5.7.') => 'warning',
                str_starts_with($row->bounce_code, '5.4.') => 'info',
                default => 'gray',
            };

            $descriptions = [
                '5.0.0' => 'Undefined / Other',
                '5.1.0' => 'Address rejected',
                '5.1.1' => 'User unknown / mailbox not found',
                '5.1.2' => 'Bad destination host / domain not found',
                '5.1.3' => 'Bad destination mailbox syntax',
                '5.1.10' => 'Recipient not found',
                '5.2.0' => 'Mailbox issue',
                '5.2.1' => 'Mailbox full / over quota',
                '5.2.2' => 'Mailbox full / over quota',
                '5.3.0' => 'Mail system full',
                '5.4.1' => 'No answer from host',
                '5.4.4' => 'Unable to route / no MX record',
                '5.5.0' => 'Protocol error / command rejected',
                '5.5.1' => 'Invalid command',
                '5.7.0' => 'Security/policy rejection',
                '5.7.1' => 'Delivery not authorized / rejected by policy',
                '5.7.2' => 'Permanently deferred / blocked IP',
                '5.7.26' => 'DMARC / authentication failure',
            ];

            $desc = $descriptions[$row->bounce_code] ?? null;
            $label = $desc ? "{$row->bounce_code} — {$desc}" : $row->bounce_code;

            $pct = $total > 0 ? round(($row->cnt / $total) * 100, 1) : 0;

            $stats[] = Stat::make($label, number_format($row->cnt))
                ->description("{$pct}%")
                ->color($color);
        }

        return $stats;
    }
}
