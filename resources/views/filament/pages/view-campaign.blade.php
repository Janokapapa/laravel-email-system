<x-filament-panels::page>
@php
    $r = $this->record;
    $stats = $this->getStats();
    $audienceStats = $this->getAudienceStats();
    $sentCount = (int) ($stats->sent ?? 0);
    $failedCount = (int) ($stats->failed ?? 0);
    $queuedCount = (int) ($stats->queued ?? 0);
    $totalRecipients = $r->total_recipients ?: 1;
    $deliveryPct = round(($r->sent_count / $totalRecipients) * 100, 1);
    $clickedCount = (int) ($stats->clicked_count ?? 0);
    $clickRate = $sentCount > 0 ? round(($clickedCount / $sentCount) * 100, 1) : 0;
    $complainedCount = (int) ($stats->complained_count ?? 0);
    $hardBounce = (int) ($stats->hard_bounce ?? 0);
    $softBounce = (int) ($stats->soft_bounce ?? 0);
    $variationCount = count($r->variations ?? []);
    $hasIssues = $failedCount > 0 || $hardBounce > 0 || $softBounce > 0 || $complainedCount > 0;

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

    {{-- Header --}}
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
        {{-- Color accent bar --}}
        <div class="h-1 bg-{{ $statusColor }}-500"></div>
        <div class="p-6">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2.5 mb-3">
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
                                {{ $variationCount }} {{ trans_choice('variation|variations', $variationCount) }}
                            </span>
                        @endif
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-1.5 leading-tight">{{ e($r->subject) }}</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ e($r->sender_display_name ?? $r->sender_name) }} &lt;{{ e($r->sender_address) }}&gt;
                    </p>
                </div>
                @if($r->sent_at)
                    <div class="text-right shrink-0 pl-4">
                        <div class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-0.5">{{ __('Sent') }}</div>
                        <div class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $r->sent_at->format('M j, Y') }}</div>
                        <div class="text-xs text-gray-400 dark:text-gray-500">{{ $r->sent_at->format('H:i') }}</div>
                    </div>
                @endif
            </div>

            {{-- Meta chips --}}
            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-800 flex flex-wrap gap-3">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-50 dark:bg-gray-800 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400">
                    <x-heroicon-m-user-group class="w-3.5 h-3.5" />
                    {{ $this->getListNames() }}
                </span>
                @if($this->getSkippedProviders())
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-50 dark:bg-gray-800 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400">
                        <x-heroicon-m-no-symbol class="w-3.5 h-3.5" />
                        {{ __('Skip') }}: {{ $this->getSkippedProviders() }}
                    </span>
                @endif
                <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-50 dark:bg-gray-800 px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400">
                    <x-heroicon-m-envelope class="w-3.5 h-3.5" />
                    {{ number_format($r->total_recipients ?? 0) }} {{ __('recipients') }}
                </span>
            </div>
        </div>
    </div>

    {{-- Big numbers row --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        {{-- Delivered --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-5 relative overflow-hidden">
            <div class="absolute top-0 right-0 w-16 h-16 -mr-4 -mt-4 rounded-full bg-emerald-500/5 dark:bg-emerald-500/10"></div>
            <div class="relative">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-500/20 flex items-center justify-center">
                        <x-heroicon-m-check-circle class="w-4.5 h-4.5 text-emerald-600 dark:text-emerald-400" />
                    </div>
                </div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($sentCount) }}</div>
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-0.5">{{ __('Delivered') }}</div>
            </div>
        </div>

        {{-- Queued --}}
        @if($queuedCount > 0)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-5 relative overflow-hidden">
                <div class="absolute top-0 right-0 w-16 h-16 -mr-4 -mt-4 rounded-full bg-amber-500/5 dark:bg-amber-500/10"></div>
                <div class="relative">
                    <div class="flex items-center gap-2 mb-2">
                        <div class="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
                            <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                        </div>
                    </div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($queuedCount) }}</div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-0.5">{{ __('Queued') }}</div>
                </div>
            </div>
        @endif

        {{-- Clicked --}}
        @if($clickedCount > 0)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-5 relative overflow-hidden">
                <div class="absolute top-0 right-0 w-16 h-16 -mr-4 -mt-4 rounded-full bg-purple-500/5 dark:bg-purple-500/10"></div>
                <div class="relative">
                    <div class="flex items-center gap-2 mb-2">
                        <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-500/20 flex items-center justify-center">
                            <x-heroicon-m-cursor-arrow-rays class="w-4.5 h-4.5 text-purple-600 dark:text-purple-400" />
                        </div>
                    </div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($clickedCount) }}</div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-0.5">{{ __('Clicked') }} <span class="text-purple-600 dark:text-purple-400">({{ $clickRate }}%)</span></div>
                </div>
            </div>
        @endif

        {{-- Failed --}}
        @if($failedCount > 0)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-5 relative overflow-hidden">
                <div class="absolute top-0 right-0 w-16 h-16 -mr-4 -mt-4 rounded-full bg-red-500/5 dark:bg-red-500/10"></div>
                <div class="relative">
                    <div class="flex items-center gap-2 mb-2">
                        <div class="w-8 h-8 rounded-lg bg-red-100 dark:bg-red-500/20 flex items-center justify-center">
                            <x-heroicon-m-x-circle class="w-4.5 h-4.5 text-red-600 dark:text-red-400" />
                        </div>
                    </div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($failedCount) }}</div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-0.5">{{ __('Failed') }}</div>
                </div>
            </div>
        @endif
    </div>

    {{-- Delivery progress --}}
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
        <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Delivery Progress') }}</span>
            <span class="text-sm tabular-nums">
                <span class="font-bold text-gray-900 dark:text-white">{{ number_format($r->sent_count ?? 0) }}</span>
                <span class="text-gray-400">/</span>
                <span class="text-gray-500 dark:text-gray-400">{{ number_format($r->total_recipients ?? 0) }}</span>
            </span>
        </div>
        <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-3 overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500 {{ $deliveryPct >= 100 ? 'bg-emerald-500' : 'bg-emerald-500' }}" style="width: {{ min($deliveryPct, 100) }}%"></div>
        </div>
        <div class="flex items-center justify-between mt-2">
            <span class="text-xs text-gray-400">0%</span>
            <span class="text-sm font-bold {{ $deliveryPct >= 100 ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-600 dark:text-gray-300' }}">{{ $deliveryPct }}%</span>
            <span class="text-xs text-gray-400">100%</span>
        </div>

        {{-- Click rate bar (only if there are clicks) --}}
        @if($clickedCount > 0)
            <div class="mt-5 pt-5 border-t border-gray-100 dark:border-gray-800">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Click Rate') }}</span>
                    <span class="text-sm font-bold text-purple-600 dark:text-purple-400">{{ $clickRate }}%</span>
                </div>
                <div class="w-full bg-gray-100 dark:bg-gray-800 rounded-full h-3 overflow-hidden">
                    <div class="bg-purple-500 h-full rounded-full transition-all duration-500" style="width: {{ min($clickRate, 100) }}%"></div>
                </div>
            </div>
        @endif
    </div>

    {{-- Issues --}}
    @if($hasIssues)
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <div class="flex items-center gap-2 mb-4">
                <x-heroicon-m-exclamation-triangle class="w-5 h-5 text-amber-500" />
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Issues') }}</h3>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                @if($failedCount > 0)
                    <div class="rounded-lg bg-red-50 dark:bg-red-500/10 p-3">
                        <div class="text-lg font-bold text-red-600 dark:text-red-400">{{ number_format($failedCount) }}</div>
                        <div class="text-xs font-medium text-red-700 dark:text-red-300">{{ __('Failed') }}</div>
                    </div>
                @endif
                @if($hardBounce > 0)
                    <div class="rounded-lg bg-red-50 dark:bg-red-500/10 p-3">
                        <div class="text-lg font-bold text-red-600 dark:text-red-400">{{ number_format($hardBounce) }}</div>
                        <div class="text-xs font-medium text-red-700 dark:text-red-300">{{ __('Hard bounce') }}</div>
                    </div>
                @endif
                @if($softBounce > 0)
                    <div class="rounded-lg bg-amber-50 dark:bg-amber-500/10 p-3">
                        <div class="text-lg font-bold text-amber-600 dark:text-amber-400">{{ number_format($softBounce) }}</div>
                        <div class="text-xs font-medium text-amber-700 dark:text-amber-300">{{ __('Soft bounce') }}</div>
                    </div>
                @endif
                @if($complainedCount > 0)
                    <div class="rounded-lg bg-orange-50 dark:bg-orange-500/10 p-3">
                        <div class="text-lg font-bold text-orange-600 dark:text-orange-400">{{ number_format($complainedCount) }}</div>
                        <div class="text-xs font-medium text-orange-700 dark:text-orange-300">{{ __('Complaints') }}</div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- By audience --}}
    @if($audienceStats->count() > 0)
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <div class="flex items-center gap-2 mb-4">
                <x-heroicon-m-rectangle-stack class="w-5 h-5 text-gray-400" />
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('By List') }}</h3>
            </div>
            <div class="space-y-3">
                @foreach($audienceStats as $stat)
                    @php
                        $group = \JanDev\EmailSystem\Models\EmailAudienceGroup::find($stat->email_audience_group_id);
                        $listSent = (int) $stat->total_sent;
                        $listClicked = (int) ($stat->clicked_count ?? 0);
                        $listFailed = (int) ($stat->failed_count ?? 0);
                        $listClickRate = $listSent > 0 ? round(($listClicked / $listSent) * 100, 1) : 0;
                    @endphp
                    <div class="rounded-lg border border-gray-100 dark:border-gray-800 p-4">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $group?->name ?? __('Unknown') }}</span>
                            </div>
                            <span class="text-xs text-gray-400">{{ \Carbon\Carbon::parse($stat->last_sent)->format('M j, Y') }}</span>
                        </div>
                        <div class="flex items-center gap-3 text-sm">
                            <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-semibold">
                                <x-heroicon-m-check class="w-3.5 h-3.5" />
                                {{ number_format($listSent) }} {{ __('sent') }}
                            </span>
                            @if($listClicked > 0)
                                <span class="inline-flex items-center gap-1 text-purple-600 dark:text-purple-400 font-semibold">
                                    <x-heroicon-m-cursor-arrow-rays class="w-3.5 h-3.5" />
                                    {{ number_format($listClicked) }} {{ __('clicked') }}
                                    <span class="text-gray-400 font-normal">({{ $listClickRate }}%)</span>
                                </span>
                            @endif
                            @if($listFailed > 0)
                                <span class="inline-flex items-center gap-1 text-red-500 dark:text-red-400 font-semibold">
                                    <x-heroicon-m-x-mark class="w-3.5 h-3.5" />
                                    {{ number_format($listFailed) }} {{ __('failed') }}
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
</x-filament-panels::page>
