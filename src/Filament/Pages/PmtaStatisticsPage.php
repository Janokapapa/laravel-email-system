<?php

namespace JanDev\EmailSystem\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use JanDev\EmailSystem\Filament\Concerns\PmtaHistoricalChartData;
use JanDev\EmailSystem\Filament\Concerns\PmtaPeriodDataLoader;

class PmtaStatisticsPage extends Page
{
    use PmtaHistoricalChartData;
    use PmtaPeriodDataLoader;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'PMTA Statistics';

    protected static ?string $title = 'PMTA Statistics';

    protected static ?string $slug = 'pmta-statistics';

    protected static ?int $navigationSort = 90;

    protected string $view = 'email-system::filament.pages.pmta-statistics';

    public static function getNavigationGroup(): ?string
    {
        return config('email-system.filament.navigation_group', 'Marketing');
    }

    public int $selectedPeriod = 7;

    public static function serverLabels(): array
    {
        return [
            'caspmta1' => 'einformations.com',
            'caspmta2' => 'exoluton.com',
            'caspmta3' => 'wavebrix.com',
            'caspmta4' => 'm1.onlinecasinoevents.com',
            'caspmta5' => 'missslotsclub.com',
        ];
    }

    public static function labelFor(string $server): string
    {
        return self::serverLabels()[$server] ?? $server;
    }

    public function setPeriod(int $days): void
    {
        if (in_array($days, [1, 7, 14, 30], true)) {
            $this->selectedPeriod = $days;
            $this->dispatch('historical-data-updated', data: $this->getHistoricalChartData());
        }
    }

    public function getServersData(): array
    {
        $servers = config('email-system.pmta.servers', []);
        $result = [];

        foreach ($servers as $server) {
            $data = $this->loadPeriodPayload($server, $this->selectedPeriod);

            if ($data === null) {
                continue;
            }

            $totals = $data['totals'] ?? [];
            $delivered = $totals['delivered'] ?? 0;
            $bouncedStop = $totals['bounced_stop'] ?? 0;
            $bouncedStopHard = $totals['bounced_stop_hard'] ?? 0;
            $bouncedStopQueue = $totals['bounced_stop_queue'] ?? 0;
            $bouncedGo = $totals['bounced_go'] ?? 0;
            $bounced = $bouncedStop + $bouncedGo;
            $total = $delivered + $bounced;
            $rate = $total > 0 ? round($delivered / $total * 100, 1) : 0;

            $result[] = [
                'server' => $server,
                'label' => self::labelFor($server),
                'delivered' => $delivered,
                'bounced_stop' => $bouncedStop,
                'bounced_stop_hard' => $bouncedStopHard,
                'bounced_stop_queue' => $bouncedStopQueue,
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
            $data = $this->loadPeriodPayload($server, $this->selectedPeriod);

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
