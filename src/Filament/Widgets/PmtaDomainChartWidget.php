<?php

namespace JanDev\EmailSystem\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class PmtaDomainChartWidget extends ChartWidget
{
    protected static ?int $sort = -1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return 'Email Volume by Domain';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true],
            ],
            'plugins' => [
                'legend' => ['position' => 'bottom'],
            ],
        ];
    }

    protected function getData(): array
    {
        $servers = config('email-system.pmta.servers', ['caspmta1', 'caspmta2', 'caspmta3']);
        $domainGroups = ['Gmail', 'Microsoft', 'Yahoo', 'iCloud', 'Other'];

        $delivered = array_fill_keys($domainGroups, 0);
        $bounced = array_fill_keys($domainGroups, 0);

        foreach ($servers as $server) {
            $data = Cache::get("pmta_stats:{$server}:7");

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
            'datasets' => [
                [
                    'label' => 'Delivered',
                    'data' => array_values($delivered),
                    'backgroundColor' => '#2ecc71',
                ],
                [
                    'label' => 'Bounced',
                    'data' => array_values($bounced),
                    'backgroundColor' => '#e74c3c',
                ],
            ],
        ];
    }
}
