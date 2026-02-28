<x-filament-panels::page>
@php
    $r = $this->record;
    $stats = $this->getStats();
    $audienceStats = $this->getAudienceStats();
    $sentCount = (int) ($stats->sent ?? 0);
    $totalRecipients = $r->total_recipients ?: 1;
    $deliveryPct = round(($r->sent_count / $totalRecipients) * 100, 1);
    $clickedCount = (int) ($stats->clicked_count ?? 0);
    $clickRate = $sentCount > 0 ? round(($clickedCount / $sentCount) * 100, 1) : 0;
    $variationCount = count($r->variations ?? []);

    $statusColor = match($r->status) {
        'sent' => 'emerald',
        'sending' => 'amber',
        'partial' => 'blue',
        'failed' => 'red',
        default => 'gray',
    };
    $statusLabel = match($r->status) {
        'new' => __('New'),
        'sending' => __('Sending'),
        'sent' => __('Sent'),
        'partial' => __('Partial'),
        'failed' => __('Failed'),
        default => ucfirst($r->status),
    };
@endphp

<div class="space-y-6">
    {{-- Header card --}}
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
        <div class="p-6">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-3 mb-3">
                        <span class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold bg-{{ $statusColor }}-50 text-{{ $statusColor }}-700 dark:bg-{{ $statusColor }}-500/10 dark:text-{{ $statusColor }}-400">
                            @if($r->status === 'sending')
                                <span class="w-2 h-2 rounded-full bg-{{ $statusColor }}-500 animate-pulse"></span>
                            @endif
                            {{ $statusLabel }}
                        </span>
                        @if($r->content_type === 'text')
                            <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                {{ __('Plain Text') }}
                            </span>
                        @endif
                        @if($variationCount > 0)
                            <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400">
                                {{ $variationCount }} {{ __('variations') }}
                            </span>
                        @endif
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">{{ e($r->subject) }}</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ e($r->sender_display_name ?? $r->sender_name) }} &lt;{{ e($r->sender_address) }}&gt;
                    </p>
                </div>
                @if($r->sent_at)
                    <div class="text-right shrink-0">
                        <div class="text-xs text-gray-400 dark:text-gray-500">{{ __('Sent') }}</div>
                        <div class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $r->sent_at->format('M j, Y') }}</div>
                        <div class="text-xs text-gray-400 dark:text-gray-500">{{ $r->sent_at->format('H:i') }}</div>
                    </div>
                @endif
            </div>

            {{-- Meta row --}}
            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-600 dark:text-gray-400">
                <div class="flex items-center gap-1.5">
                    <x-heroicon-m-user-group class="w-4 h-4 text-gray-400" />
                    {{ $this->getListNames() }}
                </div>
                @if($this->getSkippedProviders())
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-m-no-symbol class="w-4 h-4 text-gray-400" />
                        {{ __('Skip') }}: {{ $this->getSkippedProviders() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Stats grid --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        {{-- Sent --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-5 text-center">
            <div class="text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ number_format($sentCount) }}</div>
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mt-1">{{ __('Delivered') }}</div>
        </div>

        {{-- Queued --}}
        @if(($stats->queued ?? 0) > 0)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-5 text-center">
                <div class="flex items-center justify-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                    <span class="text-3xl font-bold text-amber-600 dark:text-amber-400">{{ number_format($stats->queued) }}</span>
                </div>
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mt-1">{{ __('Queued') }}</div>
            </div>
        @endif

        {{-- Clicked --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-5 text-center">
            <div class="text-3xl font-bold text-purple-600 dark:text-purple-400">{{ number_format($clickedCount) }}</div>
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mt-1">{{ __('Clicked') }}</div>
        </div>

        {{-- Failed --}}
        @if(($stats->failed ?? 0) > 0)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-5 text-center">
                <div class="text-3xl font-bold text-red-600 dark:text-red-400">{{ number_format($stats->failed) }}</div>
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mt-1">{{ __('Failed') }}</div>
            </div>
        @endif
    </div>

    {{-- Progress bars --}}
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6 space-y-5">
        {{-- Delivery --}}
        <div>
            <div class="flex justify-between text-sm mb-2">
                <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('Delivery') }}</span>
                <span class="font-semibold text-gray-900 dark:text-white">{{ number_format($r->sent_count ?? 0) }} / {{ number_format($r->total_recipients ?? 0) }} <span class="text-gray-400 font-normal">({{ $deliveryPct }}%)</span></span>
            </div>
            <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-2.5">
                <div class="bg-emerald-500 rounded-full h-2.5 transition-all duration-500" style="width: {{ min($deliveryPct, 100) }}%"></div>
            </div>
        </div>

        {{-- Click rate --}}
        @if($sentCount > 0)
            <div>
                <div class="flex justify-between text-sm mb-2">
                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('Click rate') }}</span>
                    <span class="font-semibold text-gray-900 dark:text-white">{{ $clickRate }}%</span>
                </div>
                <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-2.5">
                    <div class="bg-purple-500 rounded-full h-2.5 transition-all duration-500" style="width: {{ min($clickRate, 100) }}%"></div>
                </div>
            </div>
        @endif
    </div>

    {{-- Issues --}}
    @if(($stats->failed ?? 0) > 0 || ($stats->hard_bounce ?? 0) > 0 || ($stats->soft_bounce ?? 0) > 0 || ($stats->complained_count ?? 0) > 0)
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">{{ __('Issues') }}</h3>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                @if(($stats->failed ?? 0) > 0)
                    <div class="flex items-center justify-between bg-red-50 dark:bg-red-500/10 rounded-lg px-4 py-3">
                        <span class="text-sm text-red-700 dark:text-red-300">{{ __('Failed') }}</span>
                        <span class="text-sm font-bold text-red-600 dark:text-red-400">{{ number_format($stats->failed) }}</span>
                    </div>
                @endif
                @if(($stats->hard_bounce ?? 0) > 0)
                    <div class="flex items-center justify-between bg-red-50 dark:bg-red-500/10 rounded-lg px-4 py-3">
                        <span class="text-sm text-red-700 dark:text-red-300">{{ __('Hard bounce') }}</span>
                        <span class="text-sm font-bold text-red-600 dark:text-red-400">{{ number_format($stats->hard_bounce) }}</span>
                    </div>
                @endif
                @if(($stats->soft_bounce ?? 0) > 0)
                    <div class="flex items-center justify-between bg-amber-50 dark:bg-amber-500/10 rounded-lg px-4 py-3">
                        <span class="text-sm text-amber-700 dark:text-amber-300">{{ __('Soft bounce') }}</span>
                        <span class="text-sm font-bold text-amber-600 dark:text-amber-400">{{ number_format($stats->soft_bounce) }}</span>
                    </div>
                @endif
                @if(($stats->complained_count ?? 0) > 0)
                    <div class="flex items-center justify-between bg-orange-50 dark:bg-orange-500/10 rounded-lg px-4 py-3">
                        <span class="text-sm text-orange-700 dark:text-orange-300">{{ __('Complaints') }}</span>
                        <span class="text-sm font-bold text-orange-600 dark:text-orange-400">{{ number_format($stats->complained_count) }}</span>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- By audience --}}
    @if($audienceStats->count() > 0)
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">{{ __('By list') }}</h3>
            <div class="space-y-3">
                @foreach($audienceStats as $stat)
                    @php
                        $group = \JanDev\EmailSystem\Models\EmailAudienceGroup::find($stat->email_audience_group_id);
                        $listSent = (int) $stat->total_sent;
                        $listClicked = (int) ($stat->clicked_count ?? 0);
                        $listFailed = (int) ($stat->failed_count ?? 0);
                        $listClickRate = $listSent > 0 ? round(($listClicked / $listSent) * 100, 1) : 0;
                    @endphp
                    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $group?->name ?? __('Unknown') }}</span>
                            <span class="text-xs text-gray-400">{{ \Carbon\Carbon::parse($stat->last_sent)->format('M j, Y') }}</span>
                        </div>
                        <div class="flex items-center gap-4 text-sm">
                            <span class="text-emerald-600 dark:text-emerald-400 font-medium">{{ number_format($listSent) }} {{ __('sent') }}</span>
                            @if($listClicked > 0)
                                <span class="text-purple-600 dark:text-purple-400 font-medium">{{ number_format($listClicked) }} {{ __('clicked') }} ({{ $listClickRate }}%)</span>
                            @endif
                            @if($listFailed > 0)
                                <span class="text-red-500 dark:text-red-400 font-medium">{{ number_format($listFailed) }} {{ __('failed') }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
</x-filament-panels::page>
