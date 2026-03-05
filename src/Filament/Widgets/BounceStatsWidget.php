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

            $label = match ($row->bounce_code) {
                '5.1.1' => '5.1.1 (User unknown)',
                '5.1.2' => '5.1.2 (Bad host)',
                '5.0.0' => '5.0.0 (Undefined)',
                '5.7.1' => '5.7.1 (Rejected)',
                '5.7.2' => '5.7.2 (Deferred)',
                '5.4.4' => '5.4.4 (No route)',
                '5.2.1' => '5.2.1 (Mailbox full)',
                default => $row->bounce_code,
            };

            $pct = $total > 0 ? round(($row->cnt / $total) * 100, 1) : 0;

            $stats[] = Stat::make($label, number_format($row->cnt))
                ->description("{$pct}%")
                ->color($color);
        }

        return $stats;
    }
}
