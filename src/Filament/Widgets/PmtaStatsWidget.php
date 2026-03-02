<?php

namespace JanDev\EmailSystem\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;

class PmtaStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return 'PMTA Statistics (Last 7 days)';
    }

    protected function getStats(): array
    {
        $servers = config('email-system.pmta.servers', ['caspmta1', 'caspmta2', 'caspmta3']);
        $stats = [];
        $totalDelivered = 0;
        $totalBounced = 0;

        foreach ($servers as $server) {
            $data = Cache::get("pmta_stats:{$server}");

            if ($data === null) {
                continue;
            }

            $delivered = $data['totals']['delivered'] ?? 0;
            $bouncedStop = $data['totals']['bounced_stop'] ?? 0;
            $bouncedGo = $data['totals']['bounced_go'] ?? 0;
            $bounced = $bouncedStop + $bouncedGo;
            $total = $delivered + $bounced;
            $rate = $total > 0 ? round($delivered / $total * 100, 1) : 0;

            $totalDelivered += $delivered;
            $totalBounced += $bounced;

            $stats[] = Stat::make($server, Number::format($delivered))
                ->description("Bounced: {$bounced} · Rate: {$rate}%")
                ->color($this->rateColor($rate));
        }

        if (empty($stats)) {
            return [];
        }

        // Overall total card
        $totalAll = $totalDelivered + $totalBounced;
        $overallRate = $totalAll > 0 ? round($totalDelivered / $totalAll * 100, 1) : 0;

        $stats[] = Stat::make('Overall', Number::format($totalDelivered))
            ->description("Bounced: {$totalBounced} · Rate: {$overallRate}%")
            ->color($this->rateColor($overallRate));

        return $stats;
    }

    private function rateColor(float $rate): string
    {
        if ($rate >= 95) {
            return 'success';
        }

        if ($rate >= 80) {
            return 'warning';
        }

        return 'danger';
    }
}
