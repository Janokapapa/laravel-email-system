<?php

namespace JanDev\EmailSystem\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AudienceGroupStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    public Model | int | string | null $record = null;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $groupId = $this->record instanceof Model ? $this->record->getKey() : $this->record;

        if (! $groupId) {
            return [];
        }

        $stats = DB::table('audience_users')
            ->where('email_audience_group_id', $groupId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_active = 1 AND bounced = 0 THEN 1 ELSE 0 END) as sendable')
            ->selectRaw('SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')
            ->selectRaw('SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive')
            ->selectRaw('SUM(CASE WHEN bounced = 1 THEN 1 ELSE 0 END) as bounced')
            ->selectRaw("SUM(CASE WHEN zerobounce_status = 'valid' THEN 1 ELSE 0 END) as zb_valid")
            ->selectRaw("SUM(CASE WHEN zerobounce_status = 'invalid' THEN 1 ELSE 0 END) as zb_invalid")
            ->selectRaw("SUM(CASE WHEN zerobounce_status = 'catch_all' THEN 1 ELSE 0 END) as zb_catch_all")
            ->selectRaw("SUM(CASE WHEN zerobounce_status = 'unknown' THEN 1 ELSE 0 END) as zb_unknown")
            ->selectRaw("SUM(CASE WHEN zerobounce_status IS NULL OR zerobounce_status = 'unverified' THEN 1 ELSE 0 END) as zb_unverified")
            ->first();

        $total = (int) $stats->total;
        $pct = fn (int $value): string => $total > 0
            ? round(($value / $total) * 100, 1) . '%'
            : '0%';

        return [
            Stat::make(__('Total'), number_format($total))
                ->icon('heroicon-o-users')
                ->color('gray'),
            Stat::make(__('Sendable'), number_format((int) $stats->sendable))
                ->description(__('Active & not bounced') . ' — ' . $pct((int) $stats->sendable))
                ->color('success'),
            Stat::make(__('Bounced'), number_format((int) $stats->bounced))
                ->description($pct((int) $stats->bounced))
                ->color('danger'),
            Stat::make(__('Inactive'), number_format((int) $stats->inactive))
                ->description($pct((int) $stats->inactive))
                ->color('warning'),
            Stat::make(__('ZB Valid'), number_format((int) $stats->zb_valid))
                ->description($pct((int) $stats->zb_valid))
                ->color('success'),
            Stat::make(__('ZB Invalid'), number_format((int) $stats->zb_invalid))
                ->description($pct((int) $stats->zb_invalid))
                ->color('danger'),
            Stat::make(__('ZB Catch-all'), number_format((int) $stats->zb_catch_all))
                ->description($pct((int) $stats->zb_catch_all))
                ->color('info'),
            Stat::make(__('ZB Unverified'), number_format((int) $stats->zb_unverified))
                ->description($pct((int) $stats->zb_unverified))
                ->color('warning'),
        ];
    }
}
