<div class="space-y-6">
    {{-- Summary cards --}}
    <div class="grid grid-cols-3 gap-3">
        <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-xl p-4 text-center">
            <div class="text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ number_format($detailedStats->sent ?? 0) }}</div>
            <div class="text-sm text-emerald-700 dark:text-emerald-300">{{ __('Sent') }}</div>
        </div>
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-4 text-center">
            <div class="text-3xl font-bold text-blue-600 dark:text-blue-400">{{ number_format($detailedStats->opened_count ?? 0) }}</div>
            <div class="text-sm text-blue-700 dark:text-blue-300">{{ __('Opened') }}</div>
        </div>
        <div class="bg-purple-50 dark:bg-purple-900/20 rounded-xl p-4 text-center">
            <div class="text-3xl font-bold text-purple-600 dark:text-purple-400">{{ number_format($detailedStats->clicked_count ?? 0) }}</div>
            <div class="text-sm text-purple-700 dark:text-purple-300">{{ __('Clicked') }}</div>
        </div>
    </div>

    {{-- Open/Click rate --}}
    @if(($detailedStats->sent ?? 0) > 0)
        @php
            $openRate = round(($detailedStats->opened_count / $detailedStats->sent) * 100, 1);
            $clickRate = $detailedStats->opened_count > 0 ? round(($detailedStats->clicked_count / $detailedStats->opened_count) * 100, 1) : 0;
        @endphp
        <div class="space-y-3">
            <div>
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-600 dark:text-gray-400">{{ __('Open rate') }}</span>
                    <span class="font-semibold text-gray-900 dark:text-white">{{ $openRate }}%</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
                    <div class="bg-blue-500 rounded-full h-2.5" style="width: {{ min($openRate, 100) }}%"></div>
                </div>
            </div>

            <div>
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-gray-600 dark:text-gray-400">{{ __('Click rate') }} ({{ __('of opened') }})</span>
                    <span class="font-semibold text-gray-900 dark:text-white">{{ $clickRate }}%</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
                    <div class="bg-purple-500 rounded-full h-2.5" style="width: {{ min($clickRate, 100) }}%"></div>
                </div>
            </div>
        </div>
    @endif

    {{-- Issues --}}
    @if(($detailedStats->failed ?? 0) > 0 || ($detailedStats->hard_bounce ?? 0) > 0 || ($detailedStats->soft_bounce ?? 0) > 0 || ($detailedStats->complained_count ?? 0) > 0)
        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ __('Issues') }}</h4>
            <div class="grid grid-cols-2 gap-2">
                @if(($detailedStats->failed ?? 0) > 0)
                    <div class="flex justify-between bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-2">
                        <span class="text-red-700 dark:text-red-300">{{ __('Failed') }}</span>
                        <span class="font-semibold text-red-600 dark:text-red-400">{{ $detailedStats->failed }}</span>
                    </div>
                @endif
                @if(($detailedStats->hard_bounce ?? 0) > 0)
                    <div class="flex justify-between bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-2">
                        <span class="text-red-700 dark:text-red-300">{{ __('Hard bounce') }}</span>
                        <span class="font-semibold text-red-600 dark:text-red-400">{{ $detailedStats->hard_bounce }}</span>
                    </div>
                @endif
                @if(($detailedStats->soft_bounce ?? 0) > 0)
                    <div class="flex justify-between bg-amber-50 dark:bg-amber-900/20 rounded-lg px-3 py-2">
                        <span class="text-amber-700 dark:text-amber-300">{{ __('Soft bounce') }}</span>
                        <span class="font-semibold text-amber-600 dark:text-amber-400">{{ $detailedStats->soft_bounce }}</span>
                    </div>
                @endif
                @if(($detailedStats->complained_count ?? 0) > 0)
                    <div class="flex justify-between bg-orange-50 dark:bg-orange-900/20 rounded-lg px-3 py-2">
                        <span class="text-orange-700 dark:text-orange-300">{{ __('Complaints') }}</span>
                        <span class="font-semibold text-orange-600 dark:text-orange-400">{{ $detailedStats->complained_count }}</span>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Pending queue --}}
    @if(($detailedStats->queued ?? 0) > 0)
        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
            <div class="flex items-center justify-between bg-amber-50 dark:bg-amber-900/20 rounded-xl px-4 py-3">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                    <span class="text-amber-700 dark:text-amber-300 font-medium">{{ __('Pending in queue') }}</span>
                </div>
                <span class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ number_format($detailedStats->queued) }}</span>
            </div>
        </div>
    @endif

    {{-- By audience breakdown --}}
    @if(isset($stats) && $stats->count() > 0)
        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ __('By audience') }}</h4>
            <div class="space-y-2">
                @foreach($stats as $stat)
                    <div class="flex items-center justify-between text-sm bg-gray-50 dark:bg-gray-800 rounded-lg px-3 py-2">
                        <span class="text-gray-700 dark:text-gray-300">{{ $stat->emailAudienceGroup?->name ?? __('Unknown') }}</span>
                        <div class="text-right">
                            <span class="font-semibold text-gray-900 dark:text-white">{{ number_format($stat->total_sent) }}</span>
                            <span class="text-gray-400 text-xs ml-1">({{ \Carbon\Carbon::parse($stat->last_sent)->format('Y-m-d') }})</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
