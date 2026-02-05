<div class="p-4 space-y-4">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-gray-100 dark:bg-gray-800 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats->total ?? 0) }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('Total') }}</div>
        </div>

        <div class="bg-green-100 dark:bg-green-900 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-green-600 dark:text-green-400">{{ number_format($stats->sent ?? 0) }}</div>
            <div class="text-sm text-green-500 dark:text-green-400">{{ __('Sent') }}</div>
        </div>

        <div class="bg-yellow-100 dark:bg-yellow-900 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{{ number_format($stats->queued ?? 0) }}</div>
            <div class="text-sm text-yellow-500 dark:text-yellow-400">{{ __('Queued') }}</div>
        </div>

        <div class="bg-red-100 dark:bg-red-900 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ number_format($stats->failed ?? 0) }}</div>
            <div class="text-sm text-red-500 dark:text-red-400">{{ __('Failed') }}</div>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-4 mt-4">
        <div class="bg-blue-100 dark:bg-blue-900 rounded-lg p-4 text-center">
            <div class="text-xl font-bold text-blue-600 dark:text-blue-400">{{ number_format($stats->opened_count ?? 0) }}</div>
            <div class="text-sm text-blue-500 dark:text-blue-400">{{ __('Opened') }}</div>
            @if(($stats->sent ?? 0) > 0)
                <div class="text-xs text-gray-500 mt-1">
                    {{ round((($stats->opened_count ?? 0) / $stats->sent) * 100, 1) }}%
                </div>
            @endif
        </div>

        <div class="bg-purple-100 dark:bg-purple-900 rounded-lg p-4 text-center">
            <div class="text-xl font-bold text-purple-600 dark:text-purple-400">{{ number_format($stats->clicked_count ?? 0) }}</div>
            <div class="text-sm text-purple-500 dark:text-purple-400">{{ __('Clicked') }}</div>
            @if(($stats->sent ?? 0) > 0)
                <div class="text-xs text-gray-500 mt-1">
                    {{ round((($stats->clicked_count ?? 0) / $stats->sent) * 100, 1) }}%
                </div>
            @endif
        </div>

        <div class="bg-orange-100 dark:bg-orange-900 rounded-lg p-4 text-center">
            <div class="text-xl font-bold text-orange-600 dark:text-orange-400">{{ number_format($stats->complained_count ?? 0) }}</div>
            <div class="text-sm text-orange-500 dark:text-orange-400">{{ __('Complaints') }}</div>
        </div>
    </div>
</div>
