<x-filament-panels::page>
@assets
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
@endassets
@php
    $serverData = $this->getServerData();
    $domainTable = $this->getDomainTableData();
    $ipTable = $this->getIpTableData();
    $chart = $this->getChartData();
    $periods = [1 => '1d', 7 => '7d', 14 => '14d', 30 => '30d'];
@endphp

<style>
    .cv-card { background: var(--fi-body-bg, #fff); border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.06); border: 1px solid rgba(0,0,0,.05); overflow: hidden; }
    .dark .cv-card { background: rgb(24 24 27); border-color: rgba(255,255,255,.1); }
    .cv-pad { padding: 24px; }
    .cv-grid { display: grid; gap: 16px; }
    .cv-grid-2 { grid-template-columns: repeat(2, 1fr); }
    .cv-grid-4 { grid-template-columns: repeat(4, 1fr); }
    .cv-flex { display: flex; align-items: center; }
    .cv-between { justify-content: space-between; }
    .cv-gap-2 { gap: 8px; }
    .cv-gap-3 { gap: 12px; }
    .cv-wrap { flex-wrap: wrap; }
    .cv-h3 { font-size: 18px; font-weight: 700; color: #111827; margin: 0; line-height: 1.3; }
    .dark .cv-h3 { color: #fff; }
    .cv-sub { font-size: 13px; color: #6b7280; }
    .dark .cv-sub { color: #a1a1aa; }
    .cv-stat-num { font-size: 24px; font-weight: 700; color: #111827; line-height: 1; }
    .dark .cv-stat-num { color: #fff; }
    .cv-stat-label { font-size: 11px; color: #6b7280; margin-top: 2px; }
    .dark .cv-stat-label { color: #a1a1aa; }
    .cv-section-title { font-size: 15px; font-weight: 600; color: #111827; margin: 0; }
    .dark .cv-section-title { color: #fff; }
    .cv-accent-bar { height: 3px; }
    .cv-period-btn { padding: 6px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; border: 1px solid #e5e7eb; background: #fff; color: #6b7280; cursor: pointer; transition: all 0.15s; }
    .dark .cv-period-btn { background: rgb(39 39 42); border-color: rgb(63 63 70); color: #a1a1aa; }
    .cv-period-btn:hover { background: #f9fafb; }
    .dark .cv-period-btn:hover { background: rgb(50 50 54); }
    .cv-period-btn.active { background: #3b82f6; color: #fff; border-color: #3b82f6; }
    .dark .cv-period-btn.active { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    .cv-rate-badge { display: inline-flex; align-items: center; border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
    .cv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .cv-table th { text-align: left; padding: 10px 12px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; border-bottom: 2px solid #f3f4f6; }
    .dark .cv-table th { color: #a1a1aa; border-color: rgb(39 39 42); }
    .cv-table td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; color: #374151; }
    .dark .cv-table td { border-color: rgb(39 39 42); color: #d1d5db; }
    .cv-table tr:last-child td { border-bottom: none; }
    .cv-table .num { text-align: right; font-variant-numeric: tabular-nums; }
    .cv-table th.num { text-align: right; }
    @media (max-width: 640px) {
        .cv-grid-4 { grid-template-columns: repeat(2, 1fr); }
        .cv-grid-2 { grid-template-columns: repeat(1, 1fr); }
    }
</style>

<div style="display:flex;flex-direction:column;gap:24px;">

    {{-- Period selector --}}
    <div class="cv-flex cv-between cv-wrap cv-gap-3">
        <div class="cv-flex cv-gap-2">
            <h3 class="cv-section-title">Period</h3>
        </div>
        <div class="cv-flex cv-gap-2">
            @foreach($periods as $days => $label)
                <button
                    type="button"
                    wire:click="setPeriod({{ $days }})"
                    class="cv-period-btn {{ $this->selectedPeriod === $days ? 'active' : '' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if($serverData)
        @php
            $rateColor = $serverData['rate'] >= 95 ? '#10b981' : ($serverData['rate'] >= 80 ? '#f59e0b' : '#ef4444');
            $rateBg = $serverData['rate'] >= 95 ? '#d1fae5' : ($serverData['rate'] >= 80 ? '#fef3c7' : '#fee2e2');
            $rateText = $serverData['rate'] >= 95 ? '#047857' : ($serverData['rate'] >= 80 ? '#b45309' : '#dc2626');
        @endphp

        {{-- Summary card --}}
        <div class="cv-card">
            <div class="cv-accent-bar" style="background:{{ $rateColor }}"></div>
            <div class="cv-pad">
                <div class="cv-grid cv-grid-4">
                    <div>
                        <div class="cv-stat-num">{{ number_format($serverData['delivered']) }}</div>
                        <div class="cv-stat-label">Delivered</div>
                    </div>
                    <div>
                        <div class="cv-stat-num" style="color:#ef4444">{{ number_format($serverData['bounced_stop']) }}</div>
                        <div class="cv-stat-label">Hard Bounce</div>
                    </div>
                    <div>
                        <div class="cv-stat-num" style="color:#f59e0b">{{ number_format($serverData['bounced_go']) }}</div>
                        <div class="cv-stat-label">Soft Bounce</div>
                    </div>
                    <div>
                        <span class="cv-rate-badge" style="background:{{ $rateBg }};color:{{ $rateText }};font-size:20px;padding:8px 16px">
                            {{ $serverData['rate'] }}%
                        </span>
                        <div class="cv-stat-label" style="margin-top:6px">Delivery Rate</div>
                    </div>
                </div>
                @if($serverData['generated_at'])
                    <div class="cv-sub" style="margin-top:16px;font-size:11px">
                        Updated: {{ \Carbon\Carbon::parse($serverData['generated_at'])->diffForHumans() }}
                    </div>
                @endif
            </div>
        </div>

        <div class="cv-grid cv-grid-2">
            {{-- Domain table --}}
            <div class="cv-card cv-pad">
                <h3 class="cv-section-title" style="margin-bottom:16px">By Domain</h3>
                @if(count($domainTable) > 0)
                    <table class="cv-table">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th class="num">Delivered</th>
                                <th class="num">Bounced</th>
                                <th class="num">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($domainTable as $row)
                                @php
                                    $rowRateBg = $row['rate'] >= 95 ? '#d1fae5' : ($row['rate'] >= 80 ? '#fef3c7' : '#fee2e2');
                                    $rowRateText = $row['rate'] >= 95 ? '#047857' : ($row['rate'] >= 80 ? '#b45309' : '#dc2626');
                                @endphp
                                <tr>
                                    <td style="font-weight:600">{{ $row['domain'] }}</td>
                                    <td class="num">{{ number_format($row['delivered']) }}</td>
                                    <td class="num">{{ number_format($row['bounced']) }}</td>
                                    <td class="num">
                                        <span class="cv-rate-badge" style="background:{{ $rowRateBg }};color:{{ $rowRateText }}">{{ $row['rate'] }}%</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="cv-sub">No domain data available.</div>
                @endif
            </div>

            {{-- Domain chart --}}
            <div class="cv-card cv-pad">
                <h3 class="cv-section-title" style="margin-bottom:16px">Domain Volume</h3>
                @if(count($chart['labels']) > 0)
                    <div
                        x-data="{
                            chart: null,
                            init() {
                                this.$nextTick(() => this.renderChart())
                            },
                            renderChart() {
                                if (this.chart) this.chart.destroy()
                                if (!this.$refs.canvas) return
                                this.chart = new Chart(this.$refs.canvas, {
                                    type: 'bar',
                                    data: {
                                        labels: @js($chart['labels']),
                                        datasets: [
                                            { label: 'Delivered', data: @js($chart['delivered']), backgroundColor: '#2ecc71' },
                                            { label: 'Bounced', data: @js($chart['bounced']), backgroundColor: '#e74c3c' }
                                        ]
                                    },
                                    options: {
                                        indexAxis: 'y',
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        scales: { x: { stacked: true }, y: { stacked: true } },
                                        plugins: { legend: { position: 'bottom' } }
                                    }
                                })
                            }
                        }"
                        x-init="init()"
                        wire:key="server-chart-{{ $this->selectedPeriod }}"
                    >
                        <canvas x-ref="canvas" style="height:220px"></canvas>
                    </div>
                @else
                    <div class="cv-sub">No chart data available.</div>
                @endif
            </div>
        </div>

        {{-- IP table --}}
        <div class="cv-card cv-pad">
            <h3 class="cv-section-title" style="margin-bottom:4px">Source IPs</h3>
            <div class="cv-sub" style="margin-bottom:16px">Sorted by delivery rate (worst first)</div>
            @if(count($ipTable) > 0)
                <div style="max-height:500px;overflow-y:auto">
                    <table class="cv-table">
                        <thead>
                            <tr>
                                <th>IP Address</th>
                                <th class="num">Delivered</th>
                                <th class="num">Bounced</th>
                                <th class="num">Total</th>
                                <th class="num">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ipTable as $row)
                                @php
                                    $ipRateBg = $row['rate'] >= 95 ? '#d1fae5' : ($row['rate'] >= 80 ? '#fef3c7' : '#fee2e2');
                                    $ipRateText = $row['rate'] >= 95 ? '#047857' : ($row['rate'] >= 80 ? '#b45309' : '#dc2626');
                                @endphp
                                <tr>
                                    <td style="font-family:monospace;font-size:12px">{{ $row['ip'] }}</td>
                                    <td class="num">{{ number_format($row['delivered']) }}</td>
                                    <td class="num">{{ number_format($row['bounced']) }}</td>
                                    <td class="num">{{ number_format($row['total']) }}</td>
                                    <td class="num">
                                        <span class="cv-rate-badge" style="background:{{ $ipRateBg }};color:{{ $ipRateText }}">{{ $row['rate'] }}%</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="cv-sub">No IP data available for this period.</div>
            @endif
        </div>
    @else
        <div class="cv-card cv-pad">
            <div class="cv-sub" style="text-align:center">No statistics available for {{ $this->server }} in the {{ $this->selectedPeriod }}-day period.</div>
        </div>
    @endif

</div>
</x-filament-panels::page>
