<?php

namespace JanDev\EmailSystem\Support;

use JanDev\EmailSystem\Models\EmailAudienceGroup;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

class CampaignSummaryBuilder
{
    public static function build(Get $get): HtmlString
    {
        $name = $get('name') ?: '—';
        $senderDisplayName = $get('sender_display_name') ?: '';
        $senderAddress = $get('sender_address') ?: '';
        $replyTo = $get('reply_to') ?: '';
        $subject = $get('subject') ?: '—';
        $body = $get('body') ?: '';
        $contentType = $get('content_type') ?: 'html';
        $groupIds = $get('audience_group_ids') ?? [];
        $skipProviders = $get('skip_providers') ?? [];
        $variations = $get('variations') ?? [];

        $providerLabels = ['yahoo' => 'Yahoo', 'microsoft' => 'Microsoft', 'gmail' => 'Gmail', 'icloud' => 'iCloud'];
        $skippedNames = collect($skipProviders)->map(fn ($p) => $providerLabels[$p] ?? $p)->join(', ');

        $totalRecipients = 0;
        $listHtml = '';
        foreach ($groupIds as $id) {
            $group = EmailAudienceGroup::find($id);
            if (!$group) {
                $listHtml .= '<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:#fef2f2;border-radius:8px;font-size:13px;color:#991b1b;">⚠ ' . __('Deleted list') . '</div>';
                continue;
            }
            $active = $group->audienceUsers()->where('is_active', true)->where('bounced', false)->count();
            $totalRecipients += $active;
            $listHtml .= '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:var(--fi-body-bg,#f9fafb);border-radius:8px;font-size:13px;">'
                . '<span style="font-weight:600;color:var(--fi-fg,#111827);">' . e($group->name) . '</span>'
                . '<span style="color:#6b7280;font-weight:500;">' . number_format($active) . ' ' . __('recipients') . '</span>'
                . '</div>';
        }

        $senderFrom = $senderDisplayName
            ? (e($senderDisplayName) . ' &lt;' . e($senderAddress) . '&gt;')
            : e($senderAddress ?: '—');

        $css = '<style>'
            . '.cs-wrap{font-size:14px;color:var(--fi-fg,#111827);}'
            . '.cs-card{background:var(--fi-body-bg,#fff);border:1px solid rgba(0,0,0,.08);border-radius:12px;overflow:hidden;margin-bottom:16px;}'
            . '.dark .cs-card{border-color:rgba(255,255,255,.1);}'
            . '.cs-header{padding:16px 20px;border-bottom:1px solid rgba(0,0,0,.06);display:flex;align-items:center;gap:10px;}'
            . '.dark .cs-header{border-color:rgba(255,255,255,.08);}'
            . '.cs-header-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}'
            . '.cs-header-title{font-size:16px;font-weight:700;}'
            . '.cs-body{padding:16px 20px;}'
            . '.cs-row{display:flex;padding:10px 0;border-bottom:1px solid rgba(0,0,0,.04);align-items:baseline;gap:12px;}'
            . '.dark .cs-row{border-color:rgba(255,255,255,.05);}'
            . '.cs-row:last-child{border-bottom:none;}'
            . '.cs-label{width:120px;flex-shrink:0;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;}'
            . '.cs-value{flex:1;font-weight:500;}'
            . '.cs-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;}'
            . '.cs-total{display:flex;align-items:center;gap:8px;padding:12px;background:linear-gradient(135deg,#eff6ff,#dbeafe);border-radius:10px;margin-top:8px;}'
            . '.dark .cs-total{background:linear-gradient(135deg,#172554,#1e3a5f);}'
            . '.cs-total-num{font-size:22px;font-weight:800;color:#1d4ed8;}'
            . '.dark .cs-total-num{color:#60a5fa;}'
            . '.cs-total-label{font-size:13px;color:#3b82f6;}'
            . '.cs-lists{display:flex;flex-direction:column;gap:6px;}'
            . '.cs-details{border:1px solid rgba(0,0,0,.08);border-radius:10px;overflow:hidden;margin-top:12px;}'
            . '.dark .cs-details{border-color:rgba(255,255,255,.1);}'
            . '.cs-details summary{padding:12px 16px;cursor:pointer;font-weight:600;font-size:13px;display:flex;align-items:center;gap:8px;background:var(--fi-body-bg,#f9fafb);user-select:none;list-style:none;}'
            . '.cs-details summary::-webkit-details-marker{display:none;}'
            . '.dark .cs-details summary{background:rgb(30 30 33);}'
            . '.cs-details summary:hover{background:rgba(0,0,0,.03);}'
            . '.dark .cs-details summary:hover{background:rgba(255,255,255,.04);}'
            . '.cs-details[open] summary{border-bottom:1px solid rgba(0,0,0,.06);}'
            . '.dark .cs-details[open] summary{border-bottom-color:rgba(255,255,255,.08);}'
            . '.cs-arrow{transition:transform .2s;display:inline-block;}'
            . '.cs-details[open] .cs-arrow{transform:rotate(90deg);}'
            . '.cs-preview-frame{width:100%;border:none;background:#fff;min-height:300px;border-radius:0 0 9px 9px;}'
            . '.cs-subject-tag{font-size:12px;color:#6b7280;font-weight:400;margin-left:8px;}'
            . '</style>';

        $html = $css . '<div class="cs-wrap">';

        // ── Info Card ──
        $html .= '<div class="cs-card">';
        $html .= '<div class="cs-header">';
        $html .= '<div class="cs-header-icon" style="background:#eff6ff;color:#3b82f6;">'
            . '<svg style="width:18px;height:18px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>'
            . '</div>';
        $html .= '<span class="cs-header-title">' . __('Campaign Summary') . '</span>';
        $html .= '</div>';
        $html .= '<div class="cs-body">';

        $html .= '<div class="cs-row"><span class="cs-label">' . __('Campaign') . '</span><span class="cs-value">' . e($name) . '</span></div>';
        $html .= '<div class="cs-row"><span class="cs-label">' . __('From') . '</span><span class="cs-value">' . $senderFrom . '</span></div>';
        if ($replyTo && $replyTo !== $senderAddress) {
            $html .= '<div class="cs-row"><span class="cs-label">' . __('Reply-To') . '</span><span class="cs-value">' . e($replyTo) . '</span></div>';
        }
        $html .= '<div class="cs-row"><span class="cs-label">' . __('Subject') . '</span><span class="cs-value" style="font-weight:600;">' . e($subject) . '</span></div>';
        $html .= '<div class="cs-row"><span class="cs-label">' . __('Format') . '</span><span class="cs-value">'
            . '<span class="cs-badge" style="background:' . ($contentType === 'html' ? '#dbeafe;color:#1d4ed8' : '#f3f4f6;color:#6b7280') . '">'
            . ($contentType === 'html' ? 'HTML' : __('Plain Text'))
            . '</span></span></div>';
        if ($skippedNames) {
            $html .= '<div class="cs-row"><span class="cs-label">' . __('Skip') . '</span><span class="cs-value">'
                . '<span class="cs-badge" style="background:#fef3c7;color:#92400e;">' . e($skippedNames) . '</span></span></div>';
        }
        if (count($variations) > 0) {
            $varCount = count(array_filter($variations, fn ($v) => !empty($v['subject'] ?? '')));
            if ($varCount > 0) {
                $html .= '<div class="cs-row"><span class="cs-label">' . __('Variations') . '</span><span class="cs-value">'
                    . '<span class="cs-badge" style="background:#eef2ff;color:#4f46e5;">' . $varCount . ' ' . trans_choice('variation|variations', $varCount) . '</span></span></div>';
            }
        }

        $html .= '</div></div>';

        // ── Lists Card ──
        if (!empty($groupIds)) {
            $html .= '<div class="cs-card">';
            $html .= '<div class="cs-header">';
            $html .= '<div class="cs-header-icon" style="background:#f0fdf4;color:#16a34a;">'
                . '<svg style="width:18px;height:18px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>'
                . '</div>';
            $html .= '<span class="cs-header-title">' . __('Recipients') . '</span>';
            $html .= '</div>';
            $html .= '<div class="cs-body"><div class="cs-lists">' . $listHtml . '</div>';
            $html .= '<div class="cs-total">';
            $html .= '<span class="cs-total-num">' . number_format($totalRecipients) . '</span>';
            $html .= '<span class="cs-total-label">' . __('total recipients') . '</span>';
            $html .= '</div>';
            $html .= '</div></div>';
        }

        // ── Main Body Preview ──
        if ($body) {
            $html .= '<details class="cs-details">';
            $html .= '<summary><span class="cs-arrow">▶</span> ' . __('Email Body Preview') . '</summary>';
            if ($contentType === 'html') {
                $html .= '<iframe class="cs-preview-frame" srcdoc="' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '"></iframe>';
            } else {
                $html .= '<div style="padding:16px 20px;white-space:pre-wrap;font-family:monospace;font-size:13px;color:var(--fi-fg,#374151);background:#f9fafb;max-height:400px;overflow-y:auto;">' . e($body) . '</div>';
            }
            $html .= '</details>';
        }

        // ── Variation Previews ──
        $varIndex = 0;
        foreach ($variations as $variation) {
            $varSubject = $variation['subject'] ?? '';
            $varBody = $variation['body'] ?? '';
            if ($varSubject === '' && strip_tags($varBody) === '') continue;
            $varIndex++;
            $html .= '<details class="cs-details">';
            $html .= '<summary><span class="cs-arrow">▶</span> ' . __('Variation') . ' ' . $varIndex . '<span class="cs-subject-tag">' . e($varSubject) . '</span></summary>';
            if ($contentType === 'html' && $varBody) {
                $html .= '<iframe class="cs-preview-frame" srcdoc="' . htmlspecialchars($varBody, ENT_QUOTES, 'UTF-8') . '"></iframe>';
            } elseif ($varBody) {
                $html .= '<div style="padding:16px 20px;white-space:pre-wrap;font-family:monospace;font-size:13px;color:var(--fi-fg,#374151);background:#f9fafb;max-height:400px;overflow-y:auto;">' . e($varBody) . '</div>';
            }
            $html .= '</details>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }
}
