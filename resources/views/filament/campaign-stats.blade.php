<div class="space-y-6">
    {{-- Summary cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        {{-- Sent (handed to provider) --}}
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-4 text-center">
            <div class="text-3xl font-bold text-blue-600 dark:text-blue-400">{{ number_format($stats->sent ?? 0) }}</div>
            <div class="text-sm font-medium text-blue-700 dark:text-blue-300">{{ __('Sent') }}</div>
        </div>

        {{-- Delivered (confirmed by Mailgun) --}}
        @if(($stats->delivered ?? 0) > 0)
        <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-xl p-4 text-center">
            <div class="text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ number_format($stats->delivered) }}</div>
            <div class="text-sm font-medium text-emerald-700 dark:text-emerald-300">{{ __('Delivered') }}</div>
        </div>
        @endif

        {{-- Queued (only if > 0) --}}
        @if(($stats->queued ?? 0) > 0)
            <div class="bg-amber-50 dark:bg-amber-900/20 rounded-xl p-4 text-center">
                <div class="flex items-center justify-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                    <span class="text-3xl font-bold text-amber-600 dark:text-amber-400">{{ number_format($stats->queued) }}</span>
                </div>
                <div class="text-sm font-medium text-amber-700 dark:text-amber-300">{{ __('Queued') }}</div>
            </div>
        @endif

        {{-- Clicked --}}
        <div class="bg-purple-50 dark:bg-purple-900/20 rounded-xl p-4 text-center">
            <div class="text-3xl font-bold text-purple-600 dark:text-purple-400">{{ number_format($stats->clicked_count ?? 0) }}</div>
            <div class="text-sm font-medium text-purple-700 dark:text-purple-300">{{ __('Clicked') }}</div>
        </div>

        {{-- Failed (only if > 0) --}}
        @if(($stats->failed ?? 0) > 0)
            <div class="bg-red-50 dark:bg-red-900/20 rounded-xl p-4 text-center">
                <div class="text-3xl font-bold text-red-600 dark:text-red-400">{{ number_format($stats->failed) }}</div>
                <div class="text-sm font-medium text-red-700 dark:text-red-300">{{ __('Failed') }}</div>
            </div>
        @endif
    </div>

    {{-- Progress bar --}}
    @php
        $total = $record->total_recipients ?: 1;
        $sentCount = $record->sent_count ?? 0;
        $pct = round(($sentCount / $total) * 100, 1);
    @endphp
    <div>
        <div class="flex justify-between text-sm mb-1">
            <span class="text-gray-600 dark:text-gray-400">{{ __('Delivery progress') }}</span>
            <span class="font-semibold text-gray-900 dark:text-white">{{ number_format($sentCount) }} / {{ number_format($record->total_recipients ?? 0) }} ({{ $pct }}%)</span>
        </div>
        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
            <div class="bg-emerald-500 rounded-full h-2.5 transition-all" style="width: {{ min($pct, 100) }}%"></div>
        </div>
    </div>

    {{-- Click rate --}}
    @if(($stats->sent ?? 0) > 0 && ($stats->clicked_count ?? 0) > 0)
        @php
            $clickRate = round(($stats->clicked_count / $stats->sent) * 100, 1);
        @endphp
        <div>
            <div class="flex justify-between text-sm mb-1">
                <span class="text-gray-600 dark:text-gray-400">{{ __('Click rate') }}</span>
                <span class="font-semibold text-gray-900 dark:text-white">{{ $clickRate }}%</span>
            </div>
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
                <div class="bg-purple-500 rounded-full h-2.5 transition-all" style="width: {{ min($clickRate, 100) }}%"></div>
            </div>
        </div>
    @endif

    {{-- Issues --}}
    @if(($stats->failed ?? 0) > 0 || ($stats->hard_bounce ?? 0) > 0 || ($stats->soft_bounce ?? 0) > 0 || ($stats->complained_count ?? 0) > 0)
        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ __('Issues') }}</h4>
            <div class="grid grid-cols-2 gap-2">
                @if(($stats->failed ?? 0) > 0)
                    <div class="flex justify-between bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-2">
                        <span class="text-red-700 dark:text-red-300">{{ __('Failed') }}</span>
                        <span class="font-semibold text-red-600 dark:text-red-400">{{ $stats->failed }}</span>
                    </div>
                @endif
                @if(($stats->hard_bounce ?? 0) > 0)
                    <div class="flex justify-between bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-2">
                        <span class="text-red-700 dark:text-red-300">{{ __('Hard bounce') }}</span>
                        <span class="font-semibold text-red-600 dark:text-red-400">{{ $stats->hard_bounce }}</span>
                    </div>
                @endif
                @if(($stats->soft_bounce ?? 0) > 0)
                    <div class="flex justify-between bg-amber-50 dark:bg-amber-900/20 rounded-lg px-3 py-2">
                        <span class="text-amber-700 dark:text-amber-300">{{ __('Soft bounce') }}</span>
                        <span class="font-semibold text-amber-600 dark:text-amber-400">{{ $stats->soft_bounce }}</span>
                    </div>
                @endif
                @if(($stats->complained_count ?? 0) > 0)
                    <div class="flex justify-between bg-orange-50 dark:bg-orange-900/20 rounded-lg px-3 py-2">
                        <span class="text-orange-700 dark:text-orange-300">{{ __('Complaints') }}</span>
                        <span class="font-semibold text-orange-600 dark:text-orange-400">{{ $stats->complained_count }}</span>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- By audience breakdown --}}
    @php
        $audienceStats = \JanDev\EmailSystem\Models\EmailLog::where('campaign_id', $record->id)
            ->whereNotNull('email_audience_group_id')
            ->selectRaw("
                email_audience_group_id,
                COUNT(*) as total_sent,
                SUM(clicked) as clicked_count,
                MAX(created_at) as last_sent
            ")
            ->groupBy('email_audience_group_id')
            ->get();
    @endphp
    @if($audienceStats->count() > 0)
        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ __('By list') }}</h4>
            <div class="space-y-2">
                @foreach($audienceStats as $stat)
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg px-4 py-3">
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ \JanDev\EmailSystem\Models\EmailAudienceGroup::find($stat->email_audience_group_id)?->name ?? __('Unknown') }}
                            </span>
                            <span class="text-xs text-gray-400">{{ \Carbon\Carbon::parse($stat->last_sent)->format('Y-m-d') }}</span>
                        </div>
                        <div class="flex items-center gap-3 mt-1">
                            <span class="text-sm text-gray-900 dark:text-white font-semibold">{{ number_format($stat->total_sent) }} {{ __('sent') }}</span>
                            @if(($stat->clicked_count ?? 0) > 0)
                                <span class="text-sm text-purple-600 dark:text-purple-400">{{ number_format($stat->clicked_count) }} {{ __('clicked') }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
