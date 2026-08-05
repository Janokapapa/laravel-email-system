@php($content = $this->getContent())

<x-filament-widgets::widget>
    <x-filament::section :heading="__('What this list contains')" collapsible>
        <div style="display:flex;flex-wrap:wrap;gap:24px;margin-bottom:16px;">
            <div>
                <div style="font-size:24px;font-weight:700;">{{ number_format($content['emails']) }}</div>
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;">{{ __('Email addresses') }}</div>
            </div>
            <div>
                <div style="font-size:24px;font-weight:700;">{{ number_format($content['numbers']) }}</div>
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;">{{ __('Textable numbers') }}</div>
            </div>
            @if ($content['unusable'] > 0)
                {{-- A number that is stored but cannot be dialled is worth naming: it looks
                     like reach on every count and is not. --}}
                <div>
                    <div style="font-size:24px;font-weight:700;color:#f59e0b;">{{ number_format($content['unusable']) }}</div>
                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;">{{ __('Unusable numbers') }}</div>
                </div>
            @endif
        </div>

        @if (count($content['countries']) > 0)
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:14px;">
                    <thead>
                        <tr style="text-align:left;color:#9ca3af;font-size:12px;text-transform:uppercase;letter-spacing:.04em;">
                            <th style="padding:6px 12px 6px 0;">{{ __('Country') }}</th>
                            <th style="padding:6px 12px 6px 0;">{{ __('Prefix') }}</th>
                            <th style="padding:6px 12px 6px 0;text-align:right;">{{ __('Numbers') }}</th>
                            <th style="padding:6px 0;text-align:right;">{{ __('Per segment') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($content['countries'] as $row)
                            <tr style="border-top:1px solid rgba(128,128,128,.15);">
                                <td style="padding:6px 12px 6px 0;">{{ $row['label'] }}</td>
                                <td style="padding:6px 12px 6px 0;color:#9ca3af;">{{ $row['dial'] }}</td>
                                <td style="padding:6px 12px 6px 0;text-align:right;font-weight:600;">{{ number_format($row['count']) }}</td>
                                <td style="padding:6px 0;text-align:right;color:#9ca3af;">
                                    {{ $row['price'] === null ? '—' : number_format($row['price'], 4) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($content['numbers'] === 0)
            <p style="color:#9ca3af;font-size:14px;">{{ __('No phone numbers in this list, so it cannot be used for SMS.') }}</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
