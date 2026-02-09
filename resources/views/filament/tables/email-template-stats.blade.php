@php
    $record = $getRecord();
    $stats = $record->getSendStatistics();
    $totalSent = $stats->sum('total_sent');

    $detailedStats = \JanDev\EmailSystem\Models\EmailLog::where('email_template_id', $record->id)
        ->selectRaw("
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
            SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued_count,
            SUM(opened) as opened_count
        ")
        ->first();
@endphp

@if($totalSent > 0)
    <div class="text-sm">
        <span class="font-semibold text-gray-900 dark:text-white">{{ number_format($totalSent) }}</span>
        <span class="text-gray-500 dark:text-gray-400">{{ __('sent') }}</span>
        @if(($detailedStats->opened_count ?? 0) > 0)
            <span class="text-emerald-600 dark:text-emerald-400 ml-1">{{ number_format($detailedStats->opened_count) }} {{ __('opened') }}</span>
        @endif
        @if(($detailedStats->queued_count ?? 0) > 0)
            <span class="text-amber-600 dark:text-amber-400 ml-1">(+{{ number_format($detailedStats->queued_count) }} {{ __('pending') }})</span>
        @endif
    </div>
@else
    <span class="text-gray-400 dark:text-gray-500 text-sm">{{ __('Not sent yet') }}</span>
@endif
