<?php

namespace JanDev\EmailSystem\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

class PmtaStatisticsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'PMTA Statistics';

    protected static ?string $title = 'PMTA Statistics';

    protected static ?string $slug = 'pmta-statistics';

    protected static ?int $navigationSort = 90;

    protected string $view = 'email-system::filament.pages.pmta-statistics';

    public int $selectedPeriod = 7;

    public function setPeriod(int $days): void
    {
        if (in_array($days, [1, 7, 14, 30], true)) {
            $this->selectedPeriod = $days;
        }
    }

    public function getServersData(): array
    {
        $servers = config('email-system.pmta.servers', []);
        $result = [];

        foreach ($servers as $server) {
            $data = Cache::get("pmta_stats:{$server}:{$this->selectedPeriod}");

            if ($data === null) {
                continue;
            }

            $delivered = $data['totals']['delivered'] ?? 0;
            $bouncedStop = $data['totals']['bounced_stop'] ?? 0;
            $bouncedGo = $data['totals']['bounced_go'] ?? 0;
            $bounced = $bouncedStop + $bouncedGo;
            $total = $delivered + $bounced;
            $rate = $total > 0 ? round($delivered / $total * 100, 1) : 0;

            $result[] = [
                'server' => $server,
                'delivered' => $delivered,
                'bounced_stop' => $bouncedStop,
                'bounced_go' => $bouncedGo,
                'bounced' => $bounced,
                'total' => $total,
                'rate' => $rate,
                'generated_at' => $data['generated_at'] ?? null,
            ];
        }

        return $result;
    }

    public function getChartData(): array
    {
        $servers = config('email-system.pmta.servers', []);
        $domainGroups = ['Gmail', 'Microsoft', 'Yahoo', 'iCloud', 'Other'];

        $delivered = array_fill_keys($domainGroups, 0);
        $bounced = array_fill_keys($domainGroups, 0);

        foreach ($servers as $server) {
            $data = Cache::get("pmta_stats:{$server}:{$this->selectedPeriod}");

            if ($data === null || empty($data['domains'])) {
                continue;
            }

            foreach ($domainGroups as $group) {
                $domainData = $data['domains'][$group] ?? [];
                $delivered[$group] += $domainData['delivered'] ?? 0;
                $bounced[$group] += $domainData['bounced'] ?? 0;
            }
        }

        return [
            'labels' => $domainGroups,
            'delivered' => array_values($delivered),
            'bounced' => array_values($bounced),
        ];
    }
}
