<?php

namespace JanDev\EmailSystem\Support;

use JanDev\EmailSystem\Models\AudienceUser;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

class PlaceholderHint
{
    public static function make(): Placeholder
    {
        $tags = [
            '{{name}}',
            '{{email}}',
            '{{unsubscribe=Unsubscribe here}}',
            '{{country}}',
            '{{currency}}',
        ];

        if (class_exists(\JanDev\UserManagement\Models\Setting::class)) {
            $definitions = AudienceUser::getCustomFieldDefinitions();
            foreach ($definitions as $field) {
                $slug = $field['slug'] ?? null;
                if ($slug && preg_match('/^[a-zA-Z0-9_]+$/', $slug) && !in_array('{{' . $slug . '}}', $tags)) {
                    $tags[] = '{{' . $slug . '}}';
                }
            }
        }

        $copyIcon = '<svg xmlns="http://www.w3.org/2000/svg" style="display:inline;width:14px;height:14px;margin-left:6px;opacity:.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>';

        $btnStyle = 'display:inline-flex;align-items:center;padding:6px 12px;border-radius:8px;'
            . 'font-size:13px;font-family:ui-monospace,monospace;font-weight:500;'
            . 'background:#f3f4f6;border:1px solid #d1d5db;color:#374151;'
            . 'cursor:pointer;transition:all .15s;user-select:none;box-shadow:0 1px 2px rgba(0,0,0,.05)';

        $buttons = implode('', array_map(function ($tag) use ($copyIcon, $btnStyle) {
            $escaped = e($tag);
            $js = "navigator.clipboard.writeText('{$escaped}');"
                . "var b=this,s=b.querySelector('span');"
                . "s.textContent='" . __('Copied!') . "';"
                . "b.style.background='#dbeafe';b.style.borderColor='#3b82f6';b.style.color='#1d4ed8';"
                . "setTimeout(function(){s.textContent='{$escaped}';"
                . "b.style.background='#f3f4f6';b.style.borderColor='#d1d5db';b.style.color='#374151'},800)";

            return '<button type="button"'
                . ' onclick="' . e($js) . '"'
                . ' style="' . $btnStyle . '"'
                . ' onmouseover="this.style.background=\'#e5e7eb\';this.style.borderColor=\'#9ca3af\'"'
                . ' onmouseout="this.style.background=\'#f3f4f6\';this.style.borderColor=\'#d1d5db\'"'
                . ' title="' . __('Click to copy') . '">'
                . '<span>' . $escaped . '</span>'
                . $copyIcon
                . '</button>';
        }, $tags));

        return Placeholder::make('placeholder_hint')
            ->label(__('Available Placeholders'))
            ->content(new HtmlString('<div style="display:flex;flex-wrap:wrap;gap:8px">' . $buttons . '</div>'))
            ->columnSpanFull();
    }
}
