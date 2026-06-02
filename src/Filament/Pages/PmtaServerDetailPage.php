<?php

namespace JanDev\EmailSystem\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use JanDev\EmailSystem\Filament\Concerns\PmtaHistoricalChartData;
use JanDev\EmailSystem\Filament\Concerns\PmtaPeriodDataLoader;

class PmtaServerDetailPage extends Page
{
    use PmtaHistoricalChartData;
    use PmtaPeriodDataLoader;

    protected function historicalServerList(): array
    {
        return $this->server !== '' ? [$this->server] : [];
    }

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-server';

    protected static ?string $slug = 'pmta-statistics/{server}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'email-system::filament.pages.pmta-server-detail';

    public string $server = '';

    public int $selectedPeriod = 7;

    public function mount(string $server): void
    {
        $allowedServers = config('email-system.pmta.servers', []);

        if (!in_array($server, $allowedServers, true)) {
            abort(404);
        }

        $this->server = $server;
    }

    public function getTitle(): string
    {
        return $this->serverDisplayName() . ' — PMTA Statistics';
    }

    public function getBreadcrumbs(): array
    {
        return [
            PmtaStatisticsPage::getUrl() => 'PMTA Statistics',
            '#' => $this->serverDisplayName(),
        ];
    }

    public function serverDisplayName(): string
    {
        $label = PmtaStatisticsPage::labelFor($this->server);

        return $label === $this->server
            ? $this->server
            : $this->server . ' — ' . $label;
    }

    public function setPeriod(int $days): void
    {
        if (in_array($days, [1, 7, 14, 30], true)) {
            $this->selectedPeriod = $days;
            $this->dispatch('historical-data-updated', data: $this->getHistoricalChartData());
            $this->dispatch('domain-chart-updated', data: $this->getChartData());
        }
    }

    public function getServerData(): ?array
    {
        $data = $this->loadPeriodPayload($this->server, $this->selectedPeriod);

        if ($data === null) {
            return null;
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

        return [
            'delivered' => $delivered,
            'bounced_stop' => $bouncedStop,
            'bounced_stop_hard' => $bouncedStopHard,
            'bounced_stop_queue' => $bouncedStopQueue,
            'bounced_go' => $bouncedGo,
            'bounced' => $bounced,
            'total' => $total,
            'rate' => $rate,
            'generated_at' => $data['generated_at'] ?? null,
            'domains' => $data['domains'] ?? [],
            'ips' => $data['ips'] ?? [],
        ];
    }

    public function getDomainTableData(): array
    {
        $data = $this->loadPeriodPayload($this->server, $this->selectedPeriod);

        if ($data === null || empty($data['domains'])) {
            return [];
        }

        $rows = [];

        foreach ($data['domains'] as $group => $domainData) {
            $delivered = $domainData['delivered'] ?? 0;
            $bounced = $domainData['bounced'] ?? 0;
            $total = $delivered + $bounced;
            $rate = $total > 0 ? round($delivered / $total * 100, 1) : 0;

            $rows[] = [
                'domain' => $group,
                'delivered' => $delivered,
                'bounced' => $bounced,
                'total' => $total,
                'rate' => $rate,
            ];
        }

        return $rows;
    }

    public function getIpTableData(): array
    {
        $data = $this->loadPeriodPayload($this->server, $this->selectedPeriod);

        if ($data === null || empty($data['ips'])) {
            return [];
        }

        $rows = [];

        foreach ($data['ips'] as $ip => $ipData) {
            $delivered = $ipData['delivered'] ?? 0;
            $bounced = $ipData['bounced'] ?? 0;
            $total = $delivered + $bounced;
            $rate = $total > 0 ? round($delivered / $total * 100, 1) : 0;

            $rows[] = [
                'ip' => $ip,
                'delivered' => $delivered,
                'bounced' => $bounced,
                'total' => $total,
                'rate' => $rate,
            ];
        }

        usort($rows, fn ($a, $b) => $a['rate'] <=> $b['rate']);

        return $rows;
    }

    public function getChartData(): array
    {
        $data = $this->loadPeriodPayload($this->server, $this->selectedPeriod);

        if ($data === null || empty($data['domains'])) {
            return ['labels' => [], 'delivered' => [], 'bounced' => []];
        }

        $labels = [];
        $delivered = [];
        $bounced = [];

        foreach ($data['domains'] as $group => $domainData) {
            $labels[] = $group;
            $delivered[] = $domainData['delivered'] ?? 0;
            $bounced[] = $domainData['bounced'] ?? 0;
        }

        return [
            'labels' => $labels,
            'delivered' => $delivered,
            'bounced' => $bounced,
        ];
    }
}
