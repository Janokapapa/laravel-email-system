<?php

namespace JanDev\EmailSystem\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class AudienceStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected function getColumns(): int
    {
        return 5;
    }

    protected function getStats(): array
    {
        $stats = DB::table('audience_users')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')
            ->selectRaw('SUM(CASE WHEN bounced = 1 THEN 1 ELSE 0 END) as bounced')
            ->selectRaw("SUM(CASE WHEN zerobounce_status = 'valid' THEN 1 ELSE 0 END) as zb_valid")
            ->selectRaw("SUM(CASE WHEN zerobounce_status = 'invalid' THEN 1 ELSE 0 END) as zb_invalid")
            ->first();

        $groups = DB::table('email_audience_groups')->count();

        return [
            Stat::make(__('Lists'), number_format($groups))
                ->icon('heroicon-o-user-group')
                ->color('primary'),
            Stat::make(__('Total Subscribers'), number_format($stats->total))
                ->icon('heroicon-o-users')
                ->color('gray'),
            Stat::make(__('Active'), number_format($stats->active))
                ->description($stats->total > 0 ? round(($stats->active / $stats->total) * 100, 1) . '%' : '0%')
                ->color('success'),
            Stat::make(__('Bounced'), number_format($stats->bounced))
                ->description($stats->total > 0 ? round(($stats->bounced / $stats->total) * 100, 1) . '%' : '0%')
                ->color('danger'),
            Stat::make(__('ZB Valid'), number_format($stats->zb_valid))
                ->description($stats->total > 0 ? round(($stats->zb_valid / $stats->total) * 100, 1) . '%' : '0%')
                ->color('success'),
        ];
    }
}
