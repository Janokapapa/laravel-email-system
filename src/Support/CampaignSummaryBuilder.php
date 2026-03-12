<?php

namespace JanDev\EmailSystem\Support;

use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\EmailAudienceGroup;
use JanDev\EmailSystem\Services\ZeroBounce;
use JanDev\EmailSystem\Support\CampaignFilterBuilder;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Cache;
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
        $customFieldFilters = $get('custom_field_filters') ?? [];
        $variations = $get('variations') ?? [];

        $providerLabels = ['yahoo' => 'Yahoo', 'microsoft' => 'Microsoft', 'gmail' => 'Gmail', 'icloud' => 'iCloud'];
        $skippedNames = collect($skipProviders)->map(fn ($p) => $providerLabels[$p] ?? $p)->join(', ');

        $totalRecipients = 0;
        $listHtml = '';
        $activeFilters = array_filter($customFieldFilters, fn ($v) => $v !== null && $v !== '' && $v !== []);
        $groups = !empty($groupIds) ? EmailAudienceGroup::whereIn('id', $groupIds)->get()->keyBy('id') : collect();
        foreach ($groupIds as $id) {
            $group = $groups->get($id);
            if (!$group) {
                $listHtml .= '<div class="cs-list-deleted">⚠ ' . __('Deleted list') . '</div>';
                continue;
            }
            $query = $group->audienceUsers()->where('is_active', true)->where('bounced', false);
            CampaignFilterBuilder::applyFilters($query, $customFieldFilters);
            $active = $query->count();
            $totalRecipients += $active;
            $listHtml .= '<div class="cs-list-item">'
                . '<span class="cs-list-item-name">' . e($group->name) . '</span>'
                . '<span class="cs-list-item-count">' . number_format($active) . ' ' . __('recipients') . '</span>'
                . '</div>';
        }

        $senderFrom = $senderDisplayName
            ? (e($senderDisplayName) . ' &lt;' . e($senderAddress) . '&gt;')
            : e($senderAddress ?: '—');

        $html = static::buildCss() . '<div class="cs-wrap">';

        // ── Sender/List Mismatch Warning (from hook) ──
        $warningHtml = static::resolveSenderWarning($get('sender_name'), (array) ($get('audience_group_ids') ?? []));
        if ($warningHtml) {
            $html .= $warningHtml;
        }

        // ── Info Card ──
        $html .= '<div class="cs-card">';
        $html .= '<div class="cs-header">';
        $html .= '<div class="cs-header-icon cs-icon-blue">'
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
            . '<span class="cs-badge ' . ($contentType === 'html' ? 'cs-badge-blue' : 'cs-badge-gray') . '">'
            . ($contentType === 'html' ? 'HTML' : __('Plain Text'))
            . '</span></span></div>';
        if ($skippedNames) {
            $html .= '<div class="cs-row"><span class="cs-label">' . __('Skip') . '</span><span class="cs-value">'
                . '<span class="cs-badge cs-badge-yellow">' . e($skippedNames) . '</span></span></div>';
        }
        if (!empty($activeFilters)) {
            $fieldDefs = collect(AudienceUser::getCustomFieldDefinitions())->keyBy('slug');
            $filterBadges = '';
            foreach ($activeFilters as $slug => $value) {
                $fieldName = $fieldDefs->get($slug)['name'] ?? $slug;
                if (is_array($value)) {
                    $displayValue = e(implode(', ', $value));
                } else {
                    $displayValue = match ($value) {
                        'true'  => __('Yes'),
                        'false' => __('No'),
                        default => e((string) $value),
                    };
                }
                $filterBadges .= '<span class="cs-badge cs-badge-blue">'
                    . e($fieldName) . ': ' . $displayValue
                    . '</span>';
            }
            $html .= '<div class="cs-row"><span class="cs-label">' . __('Filters') . '</span><span class="cs-value" style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;">' . $filterBadges . '</span></div>';
        }
        if (count($variations) > 0) {
            $varCount = count(array_filter($variations, fn ($v) => !empty($v['subject'] ?? '')));
            if ($varCount > 0) {
                $html .= '<div class="cs-row"><span class="cs-label">' . __('Variations') . '</span><span class="cs-value">'
                    . '<span class="cs-badge cs-badge-indigo">' . $varCount . ' ' . trans_choice('variation|variations', $varCount) . '</span></span></div>';
            }
        }

        $html .= '</div></div>';

        // ── Lists Card ──
        if (!empty($groupIds)) {
            $html .= '<div class="cs-card">';
            $html .= '<div class="cs-header">';
            $html .= '<div class="cs-header-icon cs-icon-green">'
                . '<svg style="width:18px;height:18px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>'
                . '</div>';
            $html .= '<span class="cs-header-title">' . __('Recipients') . '</span>';
            $html .= '</div>';
            $html .= '<div class="cs-body"><div class="cs-lists">' . $listHtml . '</div>';
            if (!empty($activeFilters)) {
                $fieldDefs = $fieldDefs ?? collect(AudienceUser::getCustomFieldDefinitions())->keyBy('slug');
                $recipientFilterBadges = '';
                foreach ($activeFilters as $slug => $value) {
                    $fieldName = $fieldDefs->get($slug)['name'] ?? $slug;
                    if (is_array($value)) {
                        $displayValue = e(implode(', ', $value));
                    } else {
                        $displayValue = match ($value) {
                            'true'  => __('Yes'),
                            'false' => __('No'),
                            default => e((string) $value),
                        };
                    }
                    $recipientFilterBadges .= '<span class="cs-badge cs-badge-blue" style="margin:2px 0;">'
                        . e($fieldName) . ': ' . $displayValue
                        . '</span> ';
                }
                $html .= '<div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;padding:8px 0;border-bottom:1px solid rgba(0,0,0,.04);">'
                    . '<span class="cs-label" style="width:auto;margin-right:4px;">' . __('Filters') . ':</span>'
                    . $recipientFilterBadges
                    . '</div>';
            }
            $html .= '<div class="cs-total">';
            $html .= '<span class="cs-total-num">' . number_format($totalRecipients) . '</span>';
            $html .= '<span class="cs-total-label">' . __('total recipients') . ($activeFilters ? ' (' . __('filtered') . ')' : '') . '</span>';
            $html .= '</div>';
            $html .= '</div></div>';
        }

        // ── ZeroBounce Card ──
        $zbHtml = static::buildZeroBounceHtml($groupIds, $customFieldFilters);
        if ((string) $zbHtml !== '') {
            $html .= (string) $zbHtml;
        }

        // ── Main Body Preview ──
        if ($body) {
            $html .= '<details class="cs-details">';
            $html .= '<summary><span class="cs-arrow">▶</span> ' . __('Email Body Preview') . '</summary>';
            if ($contentType === 'html') {
                $html .= '<iframe class="cs-preview-frame" srcdoc="' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '"></iframe>';
            } else {
                $html .= '<div class="cs-plaintext">' . e($body) . '</div>';
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
                $html .= '<div class="cs-plaintext">' . e($varBody) . '</div>';
            }
            $html .= '</details>';
        }

        $html .= '</div>';

        return new HtmlString($html);
    }

    /**
     * Build the ZeroBounce stats card for the campaign summary.
     *
     * Shows unchecked, valid, catch-all, unknown, and invalid email counts
     * along with the API credit balance. Returns empty HtmlString when
     * ZeroBounce is not enabled or no audience groups are selected.
     *
     * Credits are cached for 5 seconds to avoid API spam on Livewire re-renders.
     *
     * @param  array $groupIds          Audience group IDs to count
     * @param  array $customFieldFilters Active campaign custom-field filters
     */
    public static function buildZeroBounceHtml(array $groupIds, array $customFieldFilters): HtmlString
    {
        if (!ZeroBounce::isEnabled() || empty($groupIds)) {
            return new HtmlString('');
        }

        // Build base query: active, non-bounced users in the selected groups
        $query = AudienceUser::query()
            ->whereIn('email_audience_group_id', $groupIds)
            ->where('is_active', true)
            ->where('bounced', false);

        CampaignFilterBuilder::applyFilters($query, $customFieldFilters);

        // Count each status bucket in a single query using CASE WHEN
        $result = $query->selectRaw(
            "SUM(CASE WHEN zerobounce_status IS NULL OR zerobounce_status = 'unverified' THEN 1 ELSE 0 END) AS unchecked,
             SUM(CASE WHEN zerobounce_status = 'valid'     THEN 1 ELSE 0 END) AS valid,
             SUM(CASE WHEN zerobounce_status = 'catch_all' THEN 1 ELSE 0 END) AS catch_all,
             SUM(CASE WHEN zerobounce_status = 'unknown'   THEN 1 ELSE 0 END) AS unknown,
             SUM(CASE WHEN zerobounce_status = 'invalid'   THEN 1 ELSE 0 END) AS invalid"
        )->first();

        $unchecked = (int) ($result->unchecked ?? 0);
        $valid     = (int) ($result->valid     ?? 0);
        $catchAll  = (int) ($result->catch_all ?? 0);
        $unknown   = (int) ($result->unknown   ?? 0);
        $invalid   = (int) ($result->invalid   ?? 0);

        // Credits: use sentinel to distinguish "cached null" from "not cached"
        // Cache::remember ignores null values; we need explicit null-aware caching.
        $sentinel = new \stdClass();
        $cached   = Cache::get('zerobounce_credits', $sentinel);
        if ($cached === $sentinel) {
            $cached = ZeroBounce::getCredits();
            Cache::put('zerobounce_credits', $cached, 5);
        }
        $creditsDisplay = $cached !== null ? number_format((int) $cached) : 'N/A';

        // Build HTML card using the existing cs-* CSS class pattern
        $html  = '<div class="cs-card">';
        $html .= '<div class="cs-header">';
        $html .= '<div class="cs-header-icon cs-icon-blue">'
            . '<svg style="width:18px;height:18px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">'
            . '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>'
            . '</svg>'
            . '</div>';
        $html .= '<span class="cs-header-title">ZeroBounce</span>';
        $html .= '</div>';
        $html .= '<div class="cs-body">';
        $html .= '<div class="cs-row"><span class="cs-label">' . __('Unchecked') . '</span>'
            . '<span class="cs-value"><span class="cs-badge cs-badge-gray">' . number_format($unchecked) . '</span></span></div>';
        $html .= '<div class="cs-row"><span class="cs-label">' . __('Valid') . '</span>'
            . '<span class="cs-value"><span class="cs-badge cs-badge-blue">' . number_format($valid) . '</span></span></div>';
        $html .= '<div class="cs-row"><span class="cs-label">' . __('Catch-All') . '</span>'
            . '<span class="cs-value"><span class="cs-badge cs-badge-yellow">' . number_format($catchAll) . '</span></span></div>';
        $html .= '<div class="cs-row"><span class="cs-label">' . __('Unknown') . '</span>'
            . '<span class="cs-value"><span class="cs-badge cs-badge-gray">' . number_format($unknown) . '</span></span></div>';
        $html .= '<div class="cs-row"><span class="cs-label">' . __('Invalid') . '</span>'
            . '<span class="cs-value"><span class="cs-badge cs-badge-red">' . number_format($invalid) . '</span></span></div>';
        $html .= '<div class="cs-row"><span class="cs-label">' . __('Credits') . '</span>'
            . '<span class="cs-value"><span class="cs-badge cs-badge-blue">' . $creditsDisplay . '</span></span></div>';
        $html .= '</div></div>';

        return new HtmlString($html);
    }

    private static function buildCss(): string
    {
        return '<style>'
            . '.cs-wrap{font-size:14px;color:#111827;}'
            . '.dark .cs-wrap{color:#e5e7eb;}'
            . '.cs-card{background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:12px;overflow:hidden;margin-bottom:16px;}'
            . '.dark .cs-card{background:rgb(30,30,33);border-color:rgba(255,255,255,.1);}'
            . '.cs-header{padding:16px 20px;border-bottom:1px solid rgba(0,0,0,.06);display:flex;align-items:center;gap:10px;}'
            . '.dark .cs-header{border-color:rgba(255,255,255,.08);}'
            . '.cs-header-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}'
            . '.cs-icon-blue{background:#eff6ff;color:#3b82f6;}'
            . '.dark .cs-icon-blue{background:rgba(59,130,246,.15);color:#60a5fa;}'
            . '.cs-icon-green{background:#f0fdf4;color:#16a34a;}'
            . '.dark .cs-icon-green{background:rgba(22,163,106,.15);color:#4ade80;}'
            . '.cs-header-title{font-size:16px;font-weight:700;}'
            . '.cs-body{padding:16px 20px;}'
            . '.cs-row{display:flex;padding:10px 0;border-bottom:1px solid rgba(0,0,0,.04);align-items:baseline;gap:12px;}'
            . '.dark .cs-row{border-color:rgba(255,255,255,.05);}'
            . '.cs-row:last-child{border-bottom:none;}'
            . '.cs-label{width:120px;flex-shrink:0;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;}'
            . '.dark .cs-label{color:#6b7280;}'
            . '.cs-value{flex:1;font-weight:500;}'
            . '.cs-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;}'
            . '.cs-badge-blue{background:#dbeafe;color:#1d4ed8;}'
            . '.dark .cs-badge-blue{background:rgba(59,130,246,.15);color:#93c5fd;}'
            . '.cs-badge-gray{background:#f3f4f6;color:#6b7280;}'
            . '.dark .cs-badge-gray{background:rgba(107,114,128,.15);color:#9ca3af;}'
            . '.cs-badge-yellow{background:#fef3c7;color:#92400e;}'
            . '.dark .cs-badge-yellow{background:rgba(251,191,36,.15);color:#fbbf24;}'
            . '.cs-badge-red{background:#fee2e2;color:#991b1b;}'
            . '.dark .cs-badge-red{background:rgba(239,68,68,.15);color:#fca5a5;}'
            . '.cs-badge-indigo{background:#eef2ff;color:#4f46e5;}'
            . '.dark .cs-badge-indigo{background:rgba(99,102,241,.15);color:#a5b4fc;}'
            . '.cs-total{display:flex;align-items:center;gap:8px;padding:12px;background:linear-gradient(135deg,#eff6ff,#dbeafe);border-radius:10px;margin-top:8px;}'
            . '.dark .cs-total{background:linear-gradient(135deg,#172554,#1e3a5f);}'
            . '.cs-total-num{font-size:22px;font-weight:800;color:#1d4ed8;}'
            . '.dark .cs-total-num{color:#60a5fa;}'
            . '.cs-total-label{font-size:13px;color:#3b82f6;}'
            . '.dark .cs-total-label{color:#93c5fd;}'
            . '.cs-lists{display:flex;flex-direction:column;gap:6px;}'
            . '.cs-list-item{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:#f9fafb;border-radius:8px;font-size:13px;}'
            . '.dark .cs-list-item{background:rgba(255,255,255,.05);}'
            . '.cs-list-item-name{font-weight:600;color:#111827;}'
            . '.dark .cs-list-item-name{color:#e5e7eb;}'
            . '.cs-list-item-count{color:#6b7280;font-weight:500;}'
            . '.dark .cs-list-item-count{color:#9ca3af;}'
            . '.cs-list-deleted{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#fef2f2;border-radius:8px;font-size:13px;color:#991b1b;}'
            . '.dark .cs-list-deleted{background:rgba(239,68,68,.15);color:#fca5a5;}'
            . '.cs-details{border:1px solid rgba(0,0,0,.08);border-radius:10px;overflow:hidden;margin-top:12px;}'
            . '.dark .cs-details{border-color:rgba(255,255,255,.1);}'
            . '.cs-details summary{padding:12px 16px;cursor:pointer;font-weight:600;font-size:13px;display:flex;align-items:center;gap:8px;background:#f9fafb;color:#111827;user-select:none;list-style:none;}'
            . '.dark .cs-details summary{color:#e5e7eb;}'
            . '.cs-details summary::-webkit-details-marker{display:none;}'
            . '.dark .cs-details summary{background:rgb(30 30 33);}'
            . '.cs-details summary:hover{background:rgba(0,0,0,.03);}'
            . '.dark .cs-details summary:hover{background:rgba(255,255,255,.04);}'
            . '.cs-details[open] summary{border-bottom:1px solid rgba(0,0,0,.06);}'
            . '.dark .cs-details[open] summary{border-bottom-color:rgba(255,255,255,.08);}'
            . '.cs-arrow{transition:transform .2s;display:inline-block;}'
            . '.cs-details[open] .cs-arrow{transform:rotate(90deg);}'
            . '.cs-preview-frame{width:100%;border:none;background:#fff;min-height:300px;border-radius:0 0 9px 9px;}'
            . '.cs-plaintext{padding:16px 20px;white-space:pre-wrap;font-family:monospace;font-size:13px;color:#374151;background:#f9fafb;max-height:400px;overflow-y:auto;}'
            . '.dark .cs-plaintext{color:#d1d5db;background:rgba(255,255,255,.03);}'
            . '.cs-subject-tag{font-size:12px;color:#6b7280;font-weight:400;margin-left:8px;}'
            . '.dark .cs-subject-tag{color:#9ca3af;}'
            . '</style>';
    }

    /**
     * Invoke the sender/list mismatch warning hook from config.
     * Configured via email-system.filament.campaign_sender_warnings (invokable class).
     * Returns HTML warning string or null.
     */
    public static function resolveSenderWarning(?string $senderName, array $audienceGroupIds): ?string
    {
        $class = config('email-system.filament.campaign_sender_warnings');
        if (!$class || !class_exists($class)) {
            return null;
        }

        try {
            return app($class)($senderName, $audienceGroupIds) ?: null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('CampaignSummaryBuilder sender warning hook failed: ' . $e->getMessage());
            return null;
        }
    }
}
