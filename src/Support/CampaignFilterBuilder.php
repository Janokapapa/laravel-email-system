<?php

namespace JanDev\EmailSystem\Support;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use JanDev\EmailSystem\Models\AudienceUser;
use JanDev\EmailSystem\Models\EmailAudienceGroup;

class CampaignFilterBuilder
{
    /** Slug → range options for numeric text fields */
    private const RANGE_FILTERS = [
        'deposit_count' => [
            '0'   => '0 (No deposits)',
            '1'   => '1',
            '1-5' => '1–5',
            '5+'  => '5+',
        ],
    ];

    /**
     * Returns Filament form components for each custom field definition.
     * Boolean fields → Select (All/Yes/No) with ->live().
     * Text/other fields → searchable multi-select with ->live().
     * State path: custom_field_filters.{slug}
     */
    public static function filterSchema(): array
    {
        $definitions = AudienceUser::getCustomFieldDefinitions();
        if (empty($definitions)) {
            return [];
        }

        $components = [];
        foreach ($definitions as $field) {
            $slug = $field['slug'] ?? '';
            $name = $field['name'] ?? $slug;
            $type = $field['type'] ?? 'text';

            if (!$slug) {
                continue;
            }

            $statePath = 'custom_field_filters.' . $slug;

            if ($type === 'boolean') {
                $components[] = Select::make($statePath)
                    ->label($name)
                    ->options([
                        ''      => __('All'),
                        'true'  => __('Yes'),
                        'false' => __('No'),
                    ])
                    ->default('')
                    ->live();
            } elseif (isset(self::RANGE_FILTERS[$slug])) {
                $components[] = Select::make($statePath)
                    ->label($name)
                    ->options(self::RANGE_FILTERS[$slug])
                    ->multiple()
                    ->live();
            } elseif ($type === 'select' && !empty($field['options'])) {
                $components[] = Select::make($statePath)
                    ->label($name)
                    ->options((array) $field['options'])
                    ->multiple()
                    ->searchable()
                    ->live();
            } else {
                // Text fields with virtual column index: searchable multi-select from distinct values
                $col = 'cf_' . $slug . '_idx';
                $components[] = Select::make($statePath)
                    ->label($name)
                    ->options(function () use ($col): array {
                        $values = DB::table('audience_users')
                            ->whereNotNull($col)
                            ->where($col, '!=', '')
                            ->distinct()
                            ->pluck($col)
                            ->sort()
                            ->all();

                        return array_combine($values, $values);
                    })
                    ->multiple()
                    ->searchable()
                    ->live();
            }
        }

        return $components;
    }

    /**
     * Apply custom field filters to an AudienceUser query.
     * Uses virtual generated columns cf_{slug}_idx.
     * Boolean values stored as 'true'/'false' strings by MySQL ->> operator.
     * Skips filters where value is null or empty string (= All / no filter).
     */
    public static function applyFilters($query, array $filters)
    {
        $validSlugs = collect(AudienceUser::getCustomFieldDefinitions())->pluck('slug')->all();

        foreach ($filters as $slug => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (!in_array($slug, $validSlugs, true) || !preg_match('/^[a-z0-9_]+$/', $slug)) {
                continue;
            }
            $col = 'cf_' . $slug . '_idx';

            // Range filter (e.g. deposit_count: '0', '1', '1-5', '5+')
            if (isset(self::RANGE_FILTERS[$slug])) {
                $ranges = is_array($value) ? $value : [$value];
                $query->where(function ($q) use ($col, $ranges) {
                    foreach ($ranges as $range) {
                        $q->orWhere(function ($sub) use ($col, $range) {
                            if (str_contains($range, '-')) {
                                [$min, $max] = explode('-', $range, 2);
                                $sub->whereRaw("CAST({$col} AS UNSIGNED) >= ?", [(int) $min])
                                    ->whereRaw("CAST({$col} AS UNSIGNED) <= ?", [(int) $max]);
                            } elseif (str_ends_with($range, '+')) {
                                $min = (int) rtrim($range, '+');
                                $sub->whereRaw("CAST({$col} AS UNSIGNED) >= ?", [$min]);
                            } else {
                                $sub->where($col, (string) $range);
                            }
                        });
                    }
                });
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($col, array_map('strval', $value));
            } else {
                $query->where($col, (string) $value);
            }
        }

        return $query;
    }

    /**
     * Count total estimated recipients across the given group IDs with filters applied.
     * Counts active, non-bounced users matching all non-empty custom field filters.
     */
    public static function recipientCount(array $groupIds, array $filters): int
    {
        if (empty($groupIds)) {
            return 0;
        }

        $query = AudienceUser::whereIn('email_audience_group_id', $groupIds)
            ->where('is_active', true)
            ->where('bounced', false);

        static::applyFilters($query, $filters);

        return $query->count();
    }

    /**
     * Build the live recipient count HTML for the Lists step Placeholder.
     */
    public static function buildCountHtml(array $groupIds, array $filters): HtmlString
    {
        $count = static::recipientCount($groupIds, $filters);

        return new HtmlString(
            '<div style="display:flex;align-items:baseline;gap:8px;padding:8px 0;">'
            . '<span style="font-size:1.75rem;font-weight:800;color:#3b82f6;">'
            . number_format($count)
            . '</span>'
            . '<span style="color:#6b7280;font-size:0.95rem;">'
            . __('estimated recipients')
            . '</span>'
            . '</div>'
        );
    }
}
