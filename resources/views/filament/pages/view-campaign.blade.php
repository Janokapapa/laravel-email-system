<x-filament-panels::page>
@php
    $r = $this->record;
    $stats = $this->getStats();
    $audienceStats = $this->getAudienceStats();
    $sentCount = (int) ($stats->sent ?? 0);
    $deliveredCount = (int) ($stats->delivered ?? 0);
    $failedCount = (int) ($stats->failed ?? 0);
    $queuedCount = (int) ($stats->queued ?? 0);
    $totalRecipients = $r->total_recipients ?: 1;
    $deliveryPct = round(($sentCount / max($totalRecipients, 1)) * 100, 1);
    $clickedCount = (int) ($stats->clicked_count ?? 0);
    $clickRate = $sentCount > 0 ? round(($clickedCount / $sentCount) * 100, 1) : 0;
    $complainedCount = (int) ($stats->complained_count ?? 0);
    $hardBounce = (int) ($stats->hard_bounce ?? 0);
    $softBounce = (int) ($stats->soft_bounce ?? 0);
    $variationCount = count($r->variations ?? []);
    $unsubscribedCount = (int) ($stats->unsubscribed_count ?? 0);
    $hasIssues = $failedCount > 0 || $hardBounce > 0 || $softBounce > 0 || $complainedCount > 0 || $unsubscribedCount > 0;

    $statusColors = match($r->status) {
        'sent' => ['bg' => '#d1fae5', 'text' => '#047857', 'bar' => '#10b981'],
        'sending' => ['bg' => '#fef3c7', 'text' => '#b45309', 'bar' => '#f59e0b'],
        'paused' => ['bg' => '#e0e7ff', 'text' => '#4338ca', 'bar' => '#6366f1'],
        'scheduled' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'bar' => '#3b82f6'],
        'partial' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'bar' => '#3b82f6'],
        'failed' => ['bg' => '#fee2e2', 'text' => '#dc2626', 'bar' => '#ef4444'],
        default => ['bg' => '#f3f4f6', 'text' => '#6b7280', 'bar' => '#9ca3af'],
    };
    $statusLabel = match($r->status) {
        'new' => __('New'),
        'sending' => __('Sending'),
        'paused' => __('Paused'),
        'scheduled' => __('Scheduled'),
        'sent' => __('Sent'),
        'partial' => __('Partial'),
        'failed' => __('Failed'),
        default => ucfirst($r->status),
    };
@endphp

<style>
    .cv-card { background: var(--fi-body-bg, #fff); border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.06); border: 1px solid rgba(0,0,0,.05); overflow: hidden; }
    .dark .cv-card { background: rgb(24 24 27); border-color: rgba(255,255,255,.1); }
    .cv-pad { padding: 24px; }
    .cv-grid { display: grid; gap: 16px; }
    .cv-grid-2 { grid-template-columns: repeat(2, 1fr); }
    .cv-grid-3 { grid-template-columns: repeat(3, 1fr); }
    .cv-grid-4 { grid-template-columns: repeat(4, 1fr); }
    .cv-flex { display: flex; align-items: center; }
    .cv-between { justify-content: space-between; }
    .cv-gap-2 { gap: 8px; }
    .cv-gap-3 { gap: 12px; }
    .cv-gap-4 { gap: 16px; }
    .cv-wrap { flex-wrap: wrap; }
    .cv-badge { display: inline-flex; align-items: center; gap: 6px; border-radius: 8px; padding: 4px 12px; font-size: 13px; font-weight: 600; }
    .cv-badge-sm { display: inline-flex; align-items: center; border-radius: 6px; padding: 2px 8px; font-size: 11px; font-weight: 500; }
    .cv-chip { display: inline-flex; align-items: center; gap: 6px; border-radius: 20px; padding: 5px 12px; font-size: 12px; font-weight: 500; background: #f9fafb; color: #6b7280; }
    .dark .cv-chip { background: rgb(39 39 42); color: #a1a1aa; }
    .cv-h3 { font-size: 18px; font-weight: 700; color: #111827; margin: 0 0 4px 0; line-height: 1.3; }
    .dark .cv-h3 { color: #fff; }
    .cv-sub { font-size: 13px; color: #6b7280; }
    .dark .cv-sub { color: #a1a1aa; }
    .cv-sent-info { text-align: right; flex-shrink: 0; padding-left: 16px; }
    .cv-sent-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #9ca3af; margin-bottom: 2px; }
    .cv-sent-date { font-size: 13px; font-weight: 600; color: #374151; }
    .dark .cv-sent-date { color: #e5e7eb; }
    .cv-sent-time { font-size: 11px; color: #9ca3af; }
    .cv-divider { border-top: 1px solid #f3f4f6; margin-top: 16px; padding-top: 16px; }
    .dark .cv-divider { border-color: rgb(39 39 42); }
    .cv-stat { display: flex; align-items: center; gap: 10px; }
    .cv-stat-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .cv-stat-num { font-size: 20px; font-weight: 700; color: #111827; line-height: 1; }
    .dark .cv-stat-num { color: #fff; }
    .cv-stat-num.muted { color: #d1d5db; }
    .dark .cv-stat-num.muted { color: #52525b; }
    .cv-stat-label { font-size: 11px; color: #6b7280; margin-top: 2px; }
    .dark .cv-stat-label { color: #a1a1aa; }
    .cv-progress-wrap { width: 100%; height: 10px; background: #f3f4f6; border-radius: 99px; overflow: hidden; }
    .dark .cv-progress-wrap { background: rgb(39 39 42); }
    .cv-progress-bar { height: 100%; border-radius: 99px; transition: width 0.5s ease; }
    .cv-progress-label { font-size: 13px; font-weight: 500; color: #374151; }
    .dark .cv-progress-label { color: #d1d5db; }
    .cv-progress-val { font-size: 13px; }
    .cv-progress-num { color: #111827; }
    .dark .cv-progress-num { color: #fff; }
    .cv-progress-pct { color: #374151; }
    .dark .cv-progress-pct { color: #d1d5db; }
    .cv-progress-done { color: #047857; }
    .dark .cv-progress-done { color: #34d399; }
    .cv-divider-top { border-top: 1px solid #f3f4f6; padding-top: 20px; }
    .dark .cv-divider-top { border-color: rgb(39 39 42); }
    .cv-section-title { font-size: 15px; font-weight: 600; color: #111827; margin: 0; }
    .dark .cv-section-title { color: #fff; }
    .cv-issue { border-radius: 8px; padding: 12px; }
    .cv-issue-num { font-size: 17px; font-weight: 700; }
    .cv-issue-label { font-size: 11px; font-weight: 500; }
    .cv-list-item { border-radius: 8px; border: 1px solid #f3f4f6; padding: 14px 16px; }
    .dark .cv-list-item { border-color: rgb(39 39 42); }
    .cv-list-name { font-size: 13px; font-weight: 600; color: #111827; }
    .dark .cv-list-name { color: #fff; }
    .cv-list-date { font-size: 11px; color: #9ca3af; }
    .cv-list-stat { font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
    .cv-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
    .cv-accent-bar { height: 3px; }
    .cv-clickable-stat { cursor:pointer;border-radius:8px;padding:8px;margin:-8px;transition:background .15s; }
    .cv-clickable-stat:hover { background:#f5f3ff; }
    .dark .cv-clickable-stat:hover { background:rgb(39 39 42); }
    .cv-modal-header { padding:20px 24px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;flex-shrink:0; }
    .dark .cv-modal-header { border-color:rgb(39 39 42); }
    .cv-modal-close { display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:none;background:#f3f4f6;color:#6b7280;cursor:pointer; }
    .dark .cv-modal-close { background:rgb(39 39 42);color:#a1a1aa; }
    .cv-modal-subtitle { margin:4px 0 0;font-size:13px;color:#6b7280; }
    .dark .cv-modal-subtitle { color:#a1a1aa; }
    .cv-modal-td-email { padding:10px 24px;font-size:13px;color:#111827; }
    .dark .cv-modal-td-email { color:#e4e4e7; }
    .cv-modal-td-date { padding:10px 24px;font-size:13px;color:#6b7280;text-align:right; }
    .dark .cv-modal-td-date { color:#a1a1aa; }
    .cv-modal-tr { border-top:1px solid #f3f4f6; }
    .dark .cv-modal-tr { border-color:rgb(39 39 42); }
    .cv-modal-thead { position:sticky;top:0;background:#f9fafb; }
    .dark .cv-modal-thead { background:rgb(39 39 42); }
    @keyframes spin { to { transform: rotate(360deg); } }
    .cv-countdown { font-size: 14px; font-weight: 700; color: #1d4ed8; margin-top: 6px; display: flex; align-items: center; gap: 6px; justify-content: flex-end; }
    .dark .cv-countdown { color: #60a5fa; }
    .cv-countdown-spinner { width: 14px; height: 14px; animation: spin 1s linear infinite; }
    @media (max-width: 640px) {
        .cv-grid-3, .cv-grid-4 { grid-template-columns: repeat(2, 1fr); }
    }
</style>

<div style="display:flex;flex-direction:column;gap:24px;">

    {{-- Header --}}
    <div class="cv-card">
        <div class="cv-accent-bar" style="background:{{ $statusColors['bar'] }}"></div>
        <div class="cv-pad">
            <div class="cv-flex cv-between cv-gap-4" style="align-items:flex-start">
                <div style="min-width:0;flex:1">
                    <div class="cv-flex cv-gap-2 cv-wrap" style="margin-bottom:12px">
                        <span class="cv-badge" style="background:{{ $statusColors['bg'] }};color:{{ $statusColors['text'] }}">
                            @if($r->status === 'sending')
                                <span class="cv-dot" style="background:{{ $statusColors['bar'] }};animation:pulse 2s infinite"></span>
                            @endif
                            {{ $statusLabel }}
                        </span>
                        @if(in_array($r->status, ['sending', 'partial']))
                            <button wire:click="pauseCampaign" wire:confirm="{{ __('Pause this campaign? Queued emails will not be sent until resumed.') }}" style="display:inline-flex;align-items:center;gap:4px;border-radius:6px;padding:4px 10px;font-size:12px;font-weight:500;border:1px solid #e5e7eb;background:#fff;color:#374151;cursor:pointer">
                                <svg style="width:14px;height:14px" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M5.75 3a.75.75 0 0 0-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 0 0 .75-.75V3.75A.75.75 0 0 0 7.25 3h-1.5ZM12.75 3a.75.75 0 0 0-.75.75v12.5c0 .414.336.75.75.75h1.5a.75.75 0 0 0 .75-.75V3.75a.75.75 0 0 0-.75-.75h-1.5Z"/></svg>
                                {{ __('Pause') }}
                            </button>
                        @endif
                        @if($r->status === 'paused')
                            <button wire:click="resumeCampaign" style="display:inline-flex;align-items:center;gap:4px;border-radius:6px;padding:4px 10px;font-size:12px;font-weight:500;border:1px solid #059669;background:#ecfdf5;color:#059669;cursor:pointer">
                                <svg style="width:14px;height:14px" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.3 2.84A1.5 1.5 0 0 0 4 4.11v11.78a1.5 1.5 0 0 0 2.3 1.27l9.344-5.891a1.5 1.5 0 0 0 0-2.538L6.3 2.841Z"/></svg>
                                {{ __('Resume') }}
                            </button>
                        @endif
                        @if(in_array($r->status, ['failed', 'partial']))
                            <button wire:click="retryCampaign" wire:confirm="{{ __('Retry this campaign? Already sent emails will be skipped.') }}" style="display:inline-flex;align-items:center;gap:4px;border-radius:6px;padding:4px 10px;font-size:12px;font-weight:500;border:1px solid #d97706;background:#fffbeb;color:#d97706;cursor:pointer">
                                <svg style="width:14px;height:14px" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H4.397a.75.75 0 0 0-.75.75v3.834a.75.75 0 0 0 1.5 0v-2.433l.31.311a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm-11.23-3.849a.75.75 0 0 0 1.449.39A5.5 5.5 0 0 1 14.7 10.42l.312.311h-2.433a.75.75 0 0 0 0 1.5h3.834a.75.75 0 0 0 .75-.75V7.647a.75.75 0 0 0-1.5 0v2.433l-.31-.311A7 7 0 0 0 3.674 12.907a.75.75 0 0 0 .408-1.332Z" clip-rule="evenodd"/></svg>
                                {{ __('Retry') }}
                            </button>
                        @endif
                        @if($r->status === 'scheduled')
                            <button wire:click="cancelSchedule" wire:confirm="{{ __('Cancel this schedule? The campaign will revert to draft status.') }}" style="display:inline-flex;align-items:center;gap:4px;border-radius:6px;padding:4px 10px;font-size:12px;font-weight:500;border:1px solid #dc2626;background:#fef2f2;color:#dc2626;cursor:pointer">
                                <svg style="width:14px;height:14px" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.28 7.22a.75.75 0 0 0-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 1 0 1.06 1.06L10 11.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L11.06 10l1.72-1.72a.75.75 0 0 0-1.06-1.06L10 8.94 8.28 7.22Z" clip-rule="evenodd"/></svg>
                                {{ __('Cancel Schedule') }}
                            </button>
                        @endif
                        @if($r->content_type === 'text')
                            <span class="cv-badge-sm" style="background:#f3f4f6;color:#6b7280">{{ __('Plain Text') }}</span>
                        @endif
                        @if($variationCount > 0)
                            <span class="cv-badge-sm" style="background:#eef2ff;color:#4f46e5">{{ $variationCount }} {{ trans_choice('variation|variations', $variationCount) }}</span>
                        @endif
                    </div>
                    <h3 class="cv-h3">{{ $r->subject }}</h3>
                    <p class="cv-sub" style="margin:0">{{ $r->sender_display_name ?? $r->sender_name }} &lt;{{ $r->sender_address }}&gt;@if($r->reply_to && $r->reply_to !== $r->sender_address) · Reply-To: {{ $r->reply_to }}@endif</p>
                </div>
                @if($r->status === 'scheduled' && $r->scheduled_at)
                    @php $scheduleRemaining = max(0, $r->scheduled_at->timestamp - now()->timestamp); @endphp
                    <div class="cv-sent-info" x-data="{
                        remaining: {{ $scheduleRemaining }},
                        display: '',
                        expired: {{ $scheduleRemaining <= 0 ? 'true' : 'false' }},
                        init() {
                            this.tick();
                            setInterval(() => this.tick(), 1000);
                        },
                        tick() {
                            if (this.remaining <= 0) { this.expired = true; return; }
                            let r = this.remaining;
                            let d = Math.floor(r / 86400);
                            let h = Math.floor((r % 86400) / 3600);
                            let m = Math.floor((r % 3600) / 60);
                            let s = r % 60;
                            let pad = n => n < 10 ? '0' + n : n;
                            let parts = [];
                            if (d > 0) parts.push(d + 'd');
                            parts.push(pad(h) + ':' + pad(m) + ':' + pad(s));
                            this.display = parts.join(' ');
                            this.remaining--;
                        }
                    }">
                        <div class="cv-sent-label">{{ __('Scheduled for') }}</div>
                        <div class="cv-sent-date">{{ $r->scheduled_at->format('M j, Y') }}</div>
                        <div class="cv-sent-time">{{ $r->scheduled_at->format('H:i') }} {{ config('app.timezone') }}</div>
                        <div class="cv-countdown">
                            <template x-if="!expired">
                                <span style="display:flex;align-items:center;gap:6px">
                                    <svg xmlns="http://www.w3.org/2000/svg" style="width:14px;height:14px;flex-shrink:0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm.75-13a.75.75 0 0 0-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 0 0 0-1.5h-3.25V5Z" clip-rule="evenodd"/></svg>
                                    <span x-text="display"></span>
                                </span>
                            </template>
                            <template x-if="expired">
                                <span style="display:flex;align-items:center;gap:6px">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="cv-countdown-spinner" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H4.397a.75.75 0 0 0-.75.75v3.834a.75.75 0 0 0 1.5 0v-2.433l.31.311a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm-11.23-3.849a.75.75 0 0 0 1.449.39A5.5 5.5 0 0 1 14.7 10.42l.312.311h-2.433a.75.75 0 0 0 0 1.5h3.834a.75.75 0 0 0 .75-.75V7.647a.75.75 0 0 0-1.5 0v2.433l-.31-.311A7 7 0 0 0 3.674 12.907a.75.75 0 0 0 .408-1.332Z" clip-rule="evenodd"/></svg>
                                    {{ __('Dispatching shortly...') }}
                                </span>
                            </template>
                        </div>
                    </div>
                @elseif($r->sent_at)
                    <div class="cv-sent-info">
                        <div class="cv-sent-label">{{ __('Sent') }}</div>
                        <div class="cv-sent-date">{{ $r->sent_at->format('M j, Y') }}</div>
                        <div class="cv-sent-time">{{ $r->sent_at->format('H:i') }}</div>
                    </div>
                @endif
            </div>

            <div class="cv-divider">
                <div class="cv-flex cv-gap-3 cv-wrap">
                    <span class="cv-chip">
                        <svg style="width:14px;height:14px;flex-shrink:0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM6 8a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM1.49 15.326a.78.78 0 0 1-.358-.442 3 3 0 0 1 4.308-3.516 6.484 6.484 0 0 0-1.905 3.959c-.023.222-.014.442.025.654a4.97 4.97 0 0 1-2.07-.655ZM16.44 15.98a4.97 4.97 0 0 0 2.07-.654.78.78 0 0 0 .357-.442 3 3 0 0 0-4.308-3.517 6.484 6.484 0 0 1 1.907 3.96 2.32 2.32 0 0 1-.026.654ZM18 8a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM5.304 16.19a.844.844 0 0 1-.277-.71 5 5 0 0 1 9.947 0 .843.843 0 0 1-.277.71A6.975 6.975 0 0 1 10 18a6.974 6.974 0 0 1-4.696-1.81Z"/></svg>
                        {{ $this->getListNames() }}
                    </span>
                    @if($this->getSkippedProviders())
                        <span class="cv-chip">
                            <svg style="width:14px;height:14px;flex-shrink:0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M5.965 4.904a9.461 9.461 0 0 1 9.131 9.131l-1.273 1.273A.75.75 0 0 1 12.5 14.75v-2.652a.75.75 0 0 1 .616-.736 7.985 7.985 0 0 0-.676-3.187l-6.475 6.475a.75.75 0 0 1-.53.22H2.783a.75.75 0 0 1-.557-1.254l3.739-3.739ZM10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z"/></svg>
                            {{ __('Skip') }}: {{ $this->getSkippedProviders() }}
                        </span>
                    @endif
                    <span class="cv-chip">
                        <svg style="width:14px;height:14px;flex-shrink:0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a2 2 0 0 0-2 2v1.161l8.441 4.221a1.25 1.25 0 0 0 1.118 0L19 7.162V6a2 2 0 0 0-2-2H3Z"/><path d="m19 8.839-7.77 3.885a2.75 2.75 0 0 1-2.46 0L1 8.839V14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8.839Z"/></svg>
                        {{ number_format($r->total_recipients ?? 0) }} {{ __('recipients') }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- Stats + Progress --}}
    <div class="cv-card cv-pad">
        @php
            $statCols = 2 + ($deliveredCount > 0 ? 1 : 0) + ($queuedCount > 0 ? 1 : 0);
            $statGrid = $statCols >= 4 ? 'cv-grid-4' : 'cv-grid-3';
        @endphp
        <div class="cv-grid {{ $statGrid }}" style="margin-bottom:24px">
            {{-- Sent (handed to provider) --}}
            <div class="cv-stat">
                <div class="cv-stat-icon" style="background:#dbeafe">
                    <svg style="width:18px;height:18px;color:#1d4ed8" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a2 2 0 0 0-2 2v1.161l8.441 4.221a1.25 1.25 0 0 0 1.118 0L19 7.162V6a2 2 0 0 0-2-2H3Z"/><path d="m19 8.839-7.77 3.885a2.75 2.75 0 0 1-2.46 0L1 8.839V14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8.839Z"/></svg>
                </div>
                <div>
                    <div class="cv-stat-num">{{ number_format($sentCount) }}</div>
                    <div class="cv-stat-label">{{ __('Sent') }}</div>
                </div>
            </div>

            {{-- Delivered (confirmed by Mailgun) --}}
            @if($deliveredCount > 0)
            <div class="cv-stat">
                <div class="cv-stat-icon" style="background:#d1fae5">
                    <svg style="width:18px;height:18px;color:#047857" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd"/></svg>
                </div>
                <div>
                    <div class="cv-stat-num">{{ number_format($deliveredCount) }}</div>
                    <div class="cv-stat-label">{{ __('Delivered') }}</div>
                </div>
            </div>
            @endif

            {{-- Clicked --}}
            <div class="cv-stat cv-clickable-stat" @if($clickedCount > 0) wire:click="openClickedModal" @endif>
                <div class="cv-stat-icon" style="background:{{ $clickedCount > 0 ? '#ede9fe' : '#f3f4f6' }}">
                    <svg style="width:18px;height:18px;color:{{ $clickedCount > 0 ? '#7c3aed' : '#9ca3af' }}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.111 11.89A5.5 5.5 0 1 1 15.501 8 .75.75 0 0 0 17 8a7 7 0 1 0-11.95 4.95.75.75 0 0 0 1.06-1.06Zm2.121-5.658a2.5 2.5 0 0 0 0 3.536.75.75 0 1 1-1.06 1.06A4 4 0 1 1 14 8a.75.75 0 0 1-1.5 0 2.5 2.5 0 0 0-4.268-1.768Zm2.534 1.279a.75.75 0 0 0-1.37.364l-.492 6.861a.75.75 0 0 0 1.204.65l1.043-.723.985 1.678a.75.75 0 1 0 1.292-.758l-.985-1.677 1.18-.406a.75.75 0 0 0-.2-1.441l-2.657-.308Z"/></svg>
                </div>
                <div>
                    <div class="cv-stat-num {{ $clickedCount === 0 ? 'muted' : '' }}">{{ number_format($clickedCount) }}</div>
                    <div class="cv-stat-label">
                        {{ __('Clicked') }}
                        @if($clickedCount > 0)
                            <span style="color:#7c3aed;font-weight:500">({{ $clickRate }}%)</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Queued --}}
            @if($queuedCount > 0)
                <div class="cv-stat">
                    <div class="cv-stat-icon" style="background:#fef3c7">
                        <span class="cv-dot" style="width:10px;height:10px;background:#f59e0b;animation:pulse 2s infinite"></span>
                    </div>
                    <div>
                        <div class="cv-stat-num">{{ number_format($queuedCount) }}</div>
                        <div class="cv-stat-label">{{ __('Queued') }}</div>
                    </div>
                </div>
            @endif

            {{-- Failed --}}
            <div class="cv-stat">
                <div class="cv-stat-icon" style="background:{{ $failedCount > 0 ? '#fee2e2' : '#f3f4f6' }}">
                    <svg style="width:18px;height:18px;color:{{ $failedCount > 0 ? '#dc2626' : '#9ca3af' }}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.28 7.22a.75.75 0 0 0-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 1 0 1.06 1.06L10 11.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L11.06 10l1.72-1.72a.75.75 0 0 0-1.06-1.06L10 8.94 8.28 7.22Z" clip-rule="evenodd"/></svg>
                </div>
                <div>
                    <div class="cv-stat-num {{ $failedCount === 0 ? 'muted' : '' }}">{{ number_format($failedCount) }}</div>
                    <div class="cv-stat-label">{{ __('Failed') }}</div>
                </div>
            </div>
        </div>

        {{-- Sending progress --}}
        <div style="border-top:1px solid #f3f4f6;padding-top:20px" class="cv-divider-top">
            <div class="cv-flex cv-between" style="margin-bottom:8px">
                <span class="cv-progress-label">{{ __('Sending progress') }}</span>
                <span class="cv-progress-val">
                    <strong class="cv-progress-num">{{ number_format($sentCount) }}</strong>
                    <span style="color:#9ca3af"> / {{ number_format($totalRecipients) }}</span>
                    <strong class="cv-progress-pct {{ $deliveryPct >= 100 ? 'cv-progress-done' : '' }}" style="margin-left:4px">({{ $deliveryPct }}%)</strong>
                </span>
            </div>
            <div class="cv-progress-wrap">
                <div class="cv-progress-bar" style="width:{{ min($deliveryPct, 100) }}%;background:#10b981"></div>
            </div>
        </div>

        {{-- Click rate --}}
        @if($clickedCount > 0)
            <div style="margin-top:16px">
                <div class="cv-flex cv-between" style="margin-bottom:8px">
                    <span class="cv-progress-label">{{ __('Click rate') }}</span>
                    <strong style="color:#7c3aed;font-size:13px">{{ $clickRate }}%</strong>
                </div>
                <div class="cv-progress-wrap">
                    <div class="cv-progress-bar" style="width:{{ min($clickRate, 100) }}%;background:#8b5cf6"></div>
                </div>
            </div>
        @endif
    </div>

    {{-- Issues --}}
    @if($hasIssues)
        <div class="cv-card cv-pad">
            <div class="cv-flex cv-gap-2" style="margin-bottom:16px">
                <svg style="width:16px;height:16px;color:#f59e0b;flex-shrink:0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495ZM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5Zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>
                <h3 class="cv-section-title">{{ __('Issues') }}</h3>
            </div>
            <div class="cv-grid cv-grid-4">
                @if($failedCount > 0)
                    <div class="cv-issue" style="background:#fef2f2">
                        <div class="cv-issue-num" style="color:#dc2626">{{ number_format($failedCount) }}</div>
                        <div class="cv-issue-label" style="color:#b91c1c">{{ __('Failed') }}</div>
                    </div>
                @endif
                @if($hardBounce > 0)
                    <div class="cv-issue" style="background:#fef2f2">
                        <div class="cv-issue-num" style="color:#dc2626">{{ number_format($hardBounce) }}</div>
                        <div class="cv-issue-label" style="color:#b91c1c">{{ __('Hard bounce') }}</div>
                    </div>
                @endif
                @if($softBounce > 0)
                    <div class="cv-issue" style="background:#fffbeb">
                        <div class="cv-issue-num" style="color:#d97706">{{ number_format($softBounce) }}</div>
                        <div class="cv-issue-label" style="color:#92400e">{{ __('Soft bounce') }}</div>
                    </div>
                @endif
                @if($complainedCount > 0)
                    <div class="cv-issue" style="background:#fff7ed">
                        <div class="cv-issue-num" style="color:#ea580c">{{ number_format($complainedCount) }}</div>
                        <div class="cv-issue-label" style="color:#9a3412">{{ __('Complaints') }}</div>
                    </div>
                @endif
                @if($unsubscribedCount > 0)
                    <div class="cv-issue" style="background:#fdf4ff">
                        <div class="cv-issue-num" style="color:#a21caf">{{ number_format($unsubscribedCount) }}</div>
                        <div class="cv-issue-label" style="color:#86198f">{{ __('Unsubscribed') }}</div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- By variation --}}
    @php $variationStats = $this->getVariationStats(); @endphp
    @if(!empty($variationStats))
        <div class="cv-card cv-pad">
            <div class="cv-flex cv-gap-2" style="margin-bottom:16px">
                <svg style="width:16px;height:16px;color:#9ca3af;flex-shrink:0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 4.75A.75.75 0 0 1 2.75 4h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 4.75ZM2 10a.75.75 0 0 1 .75-.75h9.5a.75.75 0 0 1 0 1.5h-9.5A.75.75 0 0 1 2 10Zm0 5.25a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd"/></svg>
                <h3 class="cv-section-title">{{ __('By Variation') }}</h3>
                <span class="cv-badge-sm" style="background:#eef2ff;color:#4f46e5">{{ count($variationStats) }} {{ trans_choice('version|versions', count($variationStats)) }}</span>
            </div>
            <div style="display:flex;flex-direction:column;gap:12px">
                @foreach($variationStats as $vIndex => $v)
                    <details class="cv-list-item" @if($vIndex === 0 && count($variationStats) <= 3) open @endif>
                        <summary style="cursor:pointer;list-style:none;outline:none">
                            <div class="cv-flex cv-between" style="margin-bottom:10px">
                                <div class="cv-flex cv-gap-2" style="flex-wrap:wrap">
                                    <span class="cv-dot" style="background: {{ $v['is_original'] ? '#10b981' : '#6366f1' }}"></span>
                                    <span class="cv-list-name">{{ $v['label'] }}</span>
                                    <span style="color:#6b7280;font-size:13px">— {{ $v['subject'] ?: '(no subject)' }}</span>
                                </div>
                                <span class="cv-list-date">{{ number_format($v['sent']) }} {{ __('sent') }}</span>
                            </div>
                            <div class="cv-flex cv-gap-3" style="flex-wrap:wrap">
                                @if($v['delivered'] > 0)
                                    <span class="cv-list-stat" style="color:#0369a1">
                                        {{ number_format($v['delivered']) }} {{ __('delivered') }}
                                    </span>
                                @endif
                                @if($v['opened'] > 0)
                                    <span class="cv-list-stat" style="color:#047857">
                                        {{ number_format($v['opened']) }} {{ __('opened') }}
                                        <span style="color:#9ca3af;font-weight:400">({{ $v['open_rate'] }}%)</span>
                                    </span>
                                @endif
                                @if($v['clicked'] > 0)
                                    <span class="cv-list-stat" style="color:#7c3aed">
                                        {{ number_format($v['clicked']) }} {{ __('clicked') }}
                                        <span style="color:#9ca3af;font-weight:400">({{ $v['click_rate'] }}%)</span>
                                    </span>
                                @endif
                                @if($v['failed'] > 0)
                                    <span class="cv-list-stat" style="color:#dc2626">
                                        {{ number_format($v['failed']) }} {{ __('failed') }}
                                    </span>
                                @endif
                                @if($v['unsubscribed'] > 0)
                                    <span class="cv-list-stat" style="color:#a21caf">
                                        {{ number_format($v['unsubscribed']) }} {{ __('unsub') }}
                                    </span>
                                @endif
                            </div>
                        </summary>
                        <div style="margin-top:14px;padding-top:14px;border-top:1px solid rgba(0,0,0,.06)">
                            <div style="font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">{{ __('Subject') }}</div>
                            <div style="font-size:14px;color:#111827;margin-bottom:14px" class="dark:!text-zinc-100">{{ $v['subject'] ?: '—' }}</div>
                            <div style="font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">{{ __('Body') }}</div>
                            <div style="font-size:13px;line-height:1.55;background:#f9fafb;border-radius:8px;padding:12px;max-height:300px;overflow:auto" class="dark:!bg-zinc-800 dark:!text-zinc-200">
                                {!! $v['body'] ?: '<em style="color:#9ca3af">' . __('No content') . '</em>' !!}
                            </div>
                        </div>
                    </details>
                @endforeach
            </div>
            @php
                $totalSent = array_sum(array_column($variationStats, 'sent'));
                $hasUntracked = collect($variationStats)->where('is_original', false)->where('sent', 0)->isNotEmpty()
                    && $totalSent > 0
                    && collect($variationStats)->where('is_original', true)->first()['sent'] === $totalSent;
            @endphp
            @if($hasUntracked)
                <div style="margin-top:14px;padding:10px 12px;background:#fef3c7;border-radius:8px;font-size:12px;color:#92400e">
                    {{ __('Older sends predate variation tracking — all logs counted under "Original" even if a variation was used.') }}
                </div>
            @endif
        </div>
    @endif

    {{-- By audience --}}
    @if($audienceStats->count() > 0)
        <div class="cv-card cv-pad">
            <div class="cv-flex cv-gap-2" style="margin-bottom:16px">
                <svg style="width:16px;height:16px;color:#9ca3af;flex-shrink:0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M5.127 3.502 5.25 3.5h9.5c.041 0 .082 0 .123.002A2.251 2.251 0 0 0 12.75 2h-5.5a2.25 2.25 0 0 0-2.123 1.502ZM1 10.25A2.25 2.25 0 0 1 3.25 8h13.5A2.25 2.25 0 0 1 19 10.25v5.5A2.25 2.25 0 0 1 16.75 18H3.25A2.25 2.25 0 0 1 1 15.75v-5.5ZM3.25 6.5c-.04 0-.082 0-.123.002A2.25 2.25 0 0 1 5.25 5h9.5c.98 0 1.814.627 2.123 1.502a3.819 3.819 0 0 0-.123-.002H3.25Z"/></svg>
                <h3 class="cv-section-title">{{ __('By List') }}</h3>
            </div>
            <div style="display:flex;flex-direction:column;gap:12px">
                @foreach($audienceStats as $stat)
                    @php
                        $group = \JanDev\EmailSystem\Models\EmailAudienceGroup::find($stat->email_audience_group_id);
                        $listSent = (int) $stat->total_sent;
                        $listClicked = (int) ($stat->clicked_count ?? 0);
                        $listFailed = (int) ($stat->failed_count ?? 0);
                        $listUnsub = (int) ($stat->unsubscribed_count ?? 0);
                        $listClickRate = $listSent > 0 ? round(($listClicked / $listSent) * 100, 1) : 0;
                    @endphp
                    <div class="cv-list-item">
                        <div class="cv-flex cv-between" style="margin-bottom:10px">
                            <div class="cv-flex cv-gap-2">
                                <span class="cv-dot" style="background:#10b981"></span>
                                <span class="cv-list-name">{{ $group?->name ?? __('Unknown') }}</span>
                            </div>
                            <span class="cv-list-date">{{ \Carbon\Carbon::parse($stat->last_sent)->format('M j, Y') }}</span>
                        </div>
                        <div class="cv-flex cv-gap-3">
                            <span class="cv-list-stat" style="color:#047857">
                                <svg style="width:14px;height:14px;flex-shrink:0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                                {{ number_format($listSent) }} {{ __('sent') }}
                            </span>
                            @if($listClicked > 0)
                                <span class="cv-list-stat" style="color:#7c3aed">
                                    <svg style="width:14px;height:14px;flex-shrink:0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.111 11.89A5.5 5.5 0 1 1 15.501 8 .75.75 0 0 0 17 8a7 7 0 1 0-11.95 4.95.75.75 0 0 0 1.06-1.06Zm2.121-5.658a2.5 2.5 0 0 0 0 3.536.75.75 0 1 1-1.06 1.06A4 4 0 1 1 14 8a.75.75 0 0 1-1.5 0 2.5 2.5 0 0 0-4.268-1.768Zm2.534 1.279a.75.75 0 0 0-1.37.364l-.492 6.861a.75.75 0 0 0 1.204.65l1.043-.723.985 1.678a.75.75 0 1 0 1.292-.758l-.985-1.677 1.18-.406a.75.75 0 0 0-.2-1.441l-2.657-.308Z"/></svg>
                                    {{ number_format($listClicked) }} {{ __('clicked') }}
                                    <span style="color:#9ca3af;font-weight:400">({{ $listClickRate }}%)</span>
                                </span>
                            @endif
                            @if($listFailed > 0)
                                <span class="cv-list-stat" style="color:#dc2626">
                                    <svg style="width:14px;height:14px;flex-shrink:0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                                    {{ number_format($listFailed) }} {{ __('failed') }}
                                </span>
                            @endif
                            @if($listUnsub > 0)
                                <span class="cv-list-stat" style="color:#a21caf">
                                    <svg style="width:14px;height:14px;flex-shrink:0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M11 5a3 3 0 1 1-6 0 3 3 0 0 1 6 0ZM2.046 15.253c-.058.468.172.92.57 1.175A9.953 9.953 0 0 0 8 18c1.982 0 3.83-.578 5.384-1.573.398-.254.628-.707.57-1.175a6.001 6.001 0 0 0-11.908 0ZM12.75 7.75a.75.75 0 0 0 0 1.5h5.5a.75.75 0 0 0 0-1.5h-5.5Z"/></svg>
                                    {{ number_format($listUnsub) }} {{ __('unsub') }}
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>

{{-- Clicked emails modal --}}
@if($this->showClickedModal)
    <div
        x-data="{ open: true }"
        x-show="open"
        x-on:keydown.escape.window="open = false; $wire.closeClickedModal()"
        style="position:fixed;inset:0;z-index:50;display:flex;align-items:center;justify-content:center"
    >
        {{-- Backdrop --}}
        <div
            x-on:click="open = false; $wire.closeClickedModal()"
            style="position:absolute;inset:0;background:rgba(0,0,0,.5)"
        ></div>

        {{-- Modal --}}
        <div style="position:relative;background:#fff;border-radius:16px;box-shadow:0 25px 50px rgba(0,0,0,.25);width:100%;max-width:600px;max-height:80vh;display:flex;flex-direction:column;margin:16px" class="dark:!bg-zinc-900">
            {{-- Header --}}
            <div class="cv-modal-header">
                <div>
                    <h3 style="margin:0;font-size:18px;font-weight:700" class="cv-section-title">{{ __('Clicked Emails') }}</h3>
                    <p class="cv-modal-subtitle">{{ number_format($clickedCount) }} {{ __('recipients clicked') }}</p>
                </div>
                <div style="display:flex;align-items:center;gap:8px">
                    <button
                        wire:click="exportClickedCsv"
                        style="display:inline-flex;align-items:center;gap:6px;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;border:none;background:#7c3aed;color:#fff;cursor:pointer"
                        onmouseover="this.style.background='#6d28d9'" onmouseout="this.style.background='#7c3aed'"
                    >
                        <svg style="width:16px;height:16px" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 2.75a.75.75 0 0 0-1.5 0v8.614L6.295 8.235a.75.75 0 1 0-1.09 1.03l4.25 4.5a.75.75 0 0 0 1.09 0l4.25-4.5a.75.75 0 0 0-1.09-1.03l-2.955 3.129V2.75Z"/><path d="M3.5 12.75a.75.75 0 0 0-1.5 0v2.5A2.75 2.75 0 0 0 4.75 18h10.5A2.75 2.75 0 0 0 18 15.25v-2.5a.75.75 0 0 0-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5Z"/></svg>
                        {{ __('Export CSV') }}
                    </button>
                    <button x-on:click="open = false; $wire.closeClickedModal()" class="cv-modal-close">
                        <svg style="width:20px;height:20px" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                    </button>
                </div>
            </div>

            {{-- Table --}}
            <div style="overflow-y:auto;flex:1">
                <table style="width:100%;border-collapse:collapse">
                    <thead>
                        <tr class="cv-modal-thead">
                            <th style="text-align:left;padding:10px 24px;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em">{{ __('Email') }}</th>
                            <th style="text-align:right;padding:10px 24px;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em">{{ __('Clicked At') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->getClickedEmails() as $log)
                            <tr class="cv-modal-tr">
                                <td class="cv-modal-td-email">{{ $log->recipient }}</td>
                                <td class="cv-modal-td-date">{{ $log->clicked_at ? \Carbon\Carbon::parse($log->clicked_at)->format('M j, Y H:i') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
</x-filament-panels::page>
