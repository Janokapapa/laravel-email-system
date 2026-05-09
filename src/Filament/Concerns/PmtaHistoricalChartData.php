<?php

namespace JanDev\EmailSystem\Filament\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

trait PmtaHistoricalChartData
{
    /**
     * Build the historical chart payload.
     *
     * 1d period → hourly granularity, last 24h, hour-level labels.
     * 7d/14d/30d → daily granularity, day-level labels. Daily rows are
     * preferred (backfill); for days where no day-row exists, hour-rows
     * are aggregated as a fallback so we never double-count.
     *
     * Rate formula: delivered / (delivered + bounced_stop + bounced_go) * 100.
     * When total = 0, rate = null (chart gap, not a misleading 0%).
     *
     * @return array{labels: array<int,string>, delivered: array<int,int|null>, rate: array<int,float|null>}
     */
    public function getHistoricalChartData(): array
    {
        $servers = $this->historicalServerList();
        if (empty($servers)) {
            return ['labels' => [], 'delivered' => [], 'rate' => []];
        }

        $period = (int) ($this->selectedPeriod ?? 7);

        if ($period === 1) {
            return $this->hourlyChartData($servers);
        }

        return $this->dailyChartData($servers, $period);
    }

    /**
     * Override in detail pages to scope to a single server.
     *
     * @return array<int,string>
     */
    protected function historicalServerList(): array
    {
        return (array) config('email-system.pmta.servers', []);
    }

    /**
     * @param  array<int,string>  $servers
     * @return array{labels: array<int,string>, delivered: array<int,int|null>, rate: array<int,float|null>}
     */
    private function hourlyChartData(array $servers): array
    {
        $cutoff = Carbon::now('UTC')->subHours(24);

        $rows = DB::table('pmta_stats_buckets')
            ->selectRaw("
                DATE_FORMAT(bucket_start, '%Y-%m-%d %H:00') AS bucket_key,
                SUM(delivered) AS delivered,
                SUM(bounced_stop + bounced_go) AS bounced
            ")
            ->where('granularity', 'hour')
            ->whereIn('server', $servers)
            ->where('bucket_start', '>=', $cutoff)
            ->groupBy('bucket_key')
            ->orderBy('bucket_key')
            ->get();

        $labels = [];
        $delivered = [];
        $rate = [];
        foreach ($rows as $row) {
            $labels[] = Carbon::parse($row->bucket_key)->format('m-d H:00');
            $d = (int) $row->delivered;
            $b = (int) $row->bounced;
            $delivered[] = $d;
            $total = $d + $b;
            $rate[] = $total > 0 ? round($d / $total * 100, 2) : null;
        }

        return ['labels' => $labels, 'delivered' => $delivered, 'rate' => $rate];
    }

    /**
     * @param  array<int,string>  $servers
     * @return array{labels: array<int,string>, delivered: array<int,int|null>, rate: array<int,float|null>}
     */
    private function dailyChartData(array $servers, int $days): array
    {
        $cutoff = Carbon::now('UTC')->subDays($days)->startOfDay();
        $serverPlaceholders = implode(',', array_fill(0, count($servers), '?'));

        // Day-rows (preferred)
        $dayRows = DB::select(
            "SELECT DATE(bucket_start) AS bucket_date,
                    SUM(delivered) AS delivered,
                    SUM(bounced_stop + bounced_go) AS bounced
             FROM pmta_stats_buckets
             WHERE granularity = 'day'
               AND bucket_start >= ?
               AND server IN ({$serverPlaceholders})
             GROUP BY bucket_date
             ORDER BY bucket_date",
            array_merge([$cutoff], $servers)
        );

        $byDate = [];
        foreach ($dayRows as $r) {
            $byDate[$r->bucket_date] = [
                'delivered' => (int) $r->delivered,
                'bounced' => (int) $r->bounced,
            ];
        }

        // Hour-rows fallback only for dates without a day-row
        $hourRows = DB::select(
            "SELECT DATE(bucket_start) AS bucket_date,
                    SUM(delivered) AS delivered,
                    SUM(bounced_stop + bounced_go) AS bounced
             FROM pmta_stats_buckets
             WHERE granularity = 'hour'
               AND bucket_start >= ?
               AND server IN ({$serverPlaceholders})
             GROUP BY bucket_date",
            array_merge([$cutoff], $servers)
        );

        foreach ($hourRows as $r) {
            if (!isset($byDate[$r->bucket_date])) {
                $byDate[$r->bucket_date] = [
                    'delivered' => (int) $r->delivered,
                    'bounced' => (int) $r->bounced,
                ];
            }
        }

        ksort($byDate);

        $labels = [];
        $delivered = [];
        $rate = [];
        foreach ($byDate as $date => $stats) {
            $labels[] = $date;
            $d = $stats['delivered'];
            $b = $stats['bounced'];
            $delivered[] = $d;
            $total = $d + $b;
            $rate[] = $total > 0 ? round($d / $total * 100, 2) : null;
        }

        return ['labels' => $labels, 'delivered' => $delivered, 'rate' => $rate];
    }
}
