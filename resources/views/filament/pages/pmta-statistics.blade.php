<x-filament-panels::page>
@assets
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
@endassets
@php
    $servers = $this->getServersData();
    $chart = $this->getChartData();
    $periods = [1 => '1d', 7 => '7d', 14 => '14d', 30 => '30d'];
    $totalDelivered = collect($servers)->sum('delivered');
    $totalBounced = collect($servers)->sum('bounced');
    $totalAll = $totalDelivered + $totalBounced;
    $overallRate = $totalAll > 0 ? round($totalDelivered / $totalAll * 100, 1) : 0;
@endphp

<style>
    .cv-card { background: var(--fi-body-bg, #fff); border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.06); border: 1px solid rgba(0,0,0,.05); overflow: hidden; }
    .dark .cv-card { background: rgb(24 24 27); border-color: rgba(255,255,255,.1); }
    .cv-pad { padding: 24px; }
    .cv-grid { display: grid; gap: 16px; }
    .cv-grid-2 { grid-template-columns: repeat(2, 1fr); }
    .cv-grid-3 { grid-template-columns: repeat(3, 1fr); }
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
    .cv-server-card { cursor: pointer; transition: box-shadow 0.15s, border-color 0.15s; }
    .cv-server-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.1); border-color: #3b82f6; }
    .cv-rate-badge { display: inline-flex; align-items: center; border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
    @media (max-width: 640px) {
        .cv-grid-3 { grid-template-columns: repeat(1, 1fr); }
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

    {{-- Overall summary --}}
    @if(count($servers) > 0)
        <div class="cv-card">
            <div class="cv-accent-bar" style="background:{{ $overallRate >= 95 ? '#10b981' : ($overallRate >= 80 ? '#f59e0b' : '#ef4444') }}"></div>
            <div class="cv-pad">
                <div class="cv-flex cv-between cv-wrap cv-gap-3">
                    <div>
                        <div class="cv-sub" style="margin-bottom:4px">Overall ({{ $this->selectedPeriod }}d)</div>
                        <div class="cv-stat-num">{{ number_format($totalDelivered) }}</div>
                        <div class="cv-stat-label">delivered</div>
                    </div>
                    <div style="text-align:right">
                        <div class="cv-sub" style="margin-bottom:4px">Bounced: {{ number_format($totalBounced) }}</div>
                        <div class="cv-rate-badge" style="background:{{ $overallRate >= 95 ? '#d1fae5' : ($overallRate >= 80 ? '#fef3c7' : '#fee2e2') }};color:{{ $overallRate >= 95 ? '#047857' : ($overallRate >= 80 ? '#b45309' : '#dc2626') }}">
                            {{ $overallRate }}% delivery rate
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Server cards --}}
    @if(count($servers) > 0)
        <div class="cv-grid cv-grid-{{ count($servers) >= 3 ? '3' : '2' }}">
            @foreach($servers as $s)
                @php
                    $rateColor = $s['rate'] >= 95 ? '#10b981' : ($s['rate'] >= 80 ? '#f59e0b' : '#ef4444');
                    $rateBg = $s['rate'] >= 95 ? '#d1fae5' : ($s['rate'] >= 80 ? '#fef3c7' : '#fee2e2');
                    $rateText = $s['rate'] >= 95 ? '#047857' : ($s['rate'] >= 80 ? '#b45309' : '#dc2626');
                @endphp
                <a href="{{ \JanDev\EmailSystem\Filament\Pages\PmtaServerDetailPage::getUrl(['server' => $s['server']]) }}" style="text-decoration:none">
                    <div class="cv-card cv-server-card">
                        <div class="cv-accent-bar" style="background:{{ $rateColor }}"></div>
                        <div class="cv-pad">
                            <div class="cv-flex cv-between" style="margin-bottom:12px">
                                <div>
                                    <h3 class="cv-h3">{{ $s['server'] }}</h3>
                                    @if(($s['label'] ?? '') !== '' && $s['label'] !== $s['server'])
                                        <div class="cv-sub" style="margin-top:2px">{{ $s['label'] }}</div>
                                    @endif
                                </div>
                                <span class="cv-rate-badge" style="background:{{ $rateBg }};color:{{ $rateText }}">
                                    {{ $s['rate'] }}%
                                </span>
                            </div>
                            <div class="cv-grid cv-grid-3" style="gap:12px">
                                <div>
                                    <div class="cv-stat-num" style="font-size:18px">{{ number_format($s['delivered']) }}</div>
                                    <div class="cv-stat-label">Delivered</div>
                                </div>
                                <div>
                                    <div class="cv-stat-num" style="font-size:18px;color:#ef4444">{{ number_format($s['bounced_stop']) }}</div>
                                    <div class="cv-stat-label">Hard bounce</div>
                                </div>
                                <div>
                                    <div class="cv-stat-num" style="font-size:18px;color:#f59e0b">{{ number_format($s['bounced_go']) }}</div>
                                    <div class="cv-stat-label">Soft bounce</div>
                                </div>
                            </div>
                            @if($s['generated_at'])
                                <div class="cv-sub" style="margin-top:12px;font-size:11px">
                                    Updated: {{ \Carbon\Carbon::parse($s['generated_at'])->diffForHumans() }}
                                </div>
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @else
        <div class="cv-card cv-pad">
            <div class="cv-sub" style="text-align:center">No PMTA statistics available. Data will appear after the next stats push.</div>
        </div>
    @endif

    {{-- Domain chart --}}
    @if(count($servers) > 0)
        <div class="cv-card cv-pad" wire:ignore>
            <h3 class="cv-section-title" style="margin-bottom:16px">Email Volume by Domain</h3>
            <div style="position:relative;height:250px">
                <canvas id="pmta-domain-chart"></canvas>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var canvas = document.getElementById('pmta-domain-chart');
                    if (canvas && typeof Chart !== 'undefined') {
                        new Chart(canvas, {
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
                        });
                    }
                });
            </script>
        </div>
    @endif

</div>
</x-filament-panels::page>
