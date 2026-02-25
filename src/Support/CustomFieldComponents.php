<?php

namespace JanDev\EmailSystem\Support;

use JanDev\EmailSystem\Models\AudienceUser;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;

class CustomFieldComponents
{
    /**
     * Generate Filament table columns for all defined custom fields.
     *
     * @return array<\Filament\Tables\Columns\Column>
     */
    public static function tableColumns(): array
    {
        $definitions = AudienceUser::getCustomFieldDefinitions();
        $columns = [];

        foreach ($definitions as $field) {
            $slug = $field['slug'] ?? null;
            $name = $field['name'] ?? $slug;
            $type = $field['type'] ?? 'text';

            if (!$slug || !preg_match('/^[a-zA-Z0-9_]+$/', $slug)) {
                continue;
            }

            $columnName = "cf_{$slug}";

            $column = match ($type) {
                'boolean' => IconColumn::make($columnName)
                    ->label(__($name))
                    ->boolean()
                    ->trueIcon('heroicon-s-check-circle')
                    ->falseIcon('heroicon-s-x-circle')
                    ->getStateUsing(fn ($record) => (bool) data_get($record->custom_fields, $slug))
                    ->toggleable()
                    ->sortable(query: fn ($query, $direction) => $query->orderBy("cf_{$slug}_idx", $direction)),

                default => TextColumn::make($columnName)
                    ->label(__($name))
                    ->getStateUsing(fn ($record) => data_get($record->custom_fields, $slug))
                    ->toggleable()
                    ->when($type === 'number', fn ($col) => $col->numeric())
                    ->when($type === 'date', fn ($col) => $col->date('Y-m-d'))
                    ->when($type === 'select', fn ($col) => $col->badge())
                    ->sortable(query: fn ($query, $direction) => $query->orderBy("cf_{$slug}_idx", $direction)),
            };

            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * Generate Filament form fields for all defined custom fields.
     * Fields use custom_fields[slug] naming so Filament maps them to the JSON column.
     *
     * @return array<\Filament\Forms\Components\Field>
     */
    public static function formFields(): array
    {
        $definitions = AudienceUser::getCustomFieldDefinitions();
        $fields = [];

        foreach ($definitions as $field) {
            $slug = $field['slug'] ?? null;
            $name = $field['name'] ?? $slug;
            $type = $field['type'] ?? 'text';
            $required = $field['required'] ?? false;
            $options = $field['options'] ?? [];

            if (!$slug || !preg_match('/^[a-zA-Z0-9_]+$/', $slug)) {
                continue;
            }

            $component = match ($type) {
                'boolean' => Toggle::make("custom_fields.{$slug}")
                    ->label(__($name))
                    ->default(false),

                'select' => Select::make("custom_fields.{$slug}")
                    ->label(__($name))
                    ->options(array_combine($options, $options))
                    ->placeholder(__('Select...'))
                    ->when($required, fn ($c) => $c->required()),

                'date' => DatePicker::make("custom_fields.{$slug}")
                    ->label(__($name))
                    ->native(false)
                    ->displayFormat('Y-m-d')
                    ->when($required, fn ($c) => $c->required()),

                'number' => TextInput::make("custom_fields.{$slug}")
                    ->label(__($name))
                    ->numeric()
                    ->when($required, fn ($c) => $c->required()),

                default => TextInput::make("custom_fields.{$slug}")
                    ->label(__($name))
                    ->when($required, fn ($c) => $c->required()),
            };

            $fields[] = $component;
        }

        return $fields;
    }

    /**
     * Generate Filament table filters for all defined custom fields.
     * Filters use the virtual generated column (cf_<slug>_idx) for index-backed queries.
     *
     * @return array<\Filament\Tables\Filters\BaseFilter>
     */
    public static function tableFilters(): array
    {
        $definitions = AudienceUser::getCustomFieldDefinitions();
        $filters = [];

        foreach ($definitions as $field) {
            $slug = $field['slug'] ?? null;
            $name = $field['name'] ?? $slug;
            $type = $field['type'] ?? 'text';
            $options = $field['options'] ?? [];

            if (!$slug || !preg_match('/^[a-zA-Z0-9_]+$/', $slug)) {
                continue;
            }

            $safeSlug = preg_replace('/[^a-zA-Z0-9_]/', '', $slug);
            $columnIdx = "cf_{$safeSlug}_idx";

            $filter = match ($type) {
                'boolean' => SelectFilter::make("cf_{$slug}")
                    ->label(__($name))
                    ->options([
                        '1' => __('Yes'),
                        '0' => __('No'),
                    ])
                    ->placeholder(__('All'))
                    ->query(fn ($query, $data) => isset($data['value']) && $data['value'] !== ''
                        ? $query->where($columnIdx, $data['value'])
                        : $query),

                'select' => SelectFilter::make("cf_{$slug}")
                    ->label(__($name))
                    ->options(array_combine($options, $options))
                    ->placeholder(__('All'))
                    ->query(fn ($query, $data) => isset($data['value']) && $data['value'] !== ''
                        ? $query->where($columnIdx, $data['value'])
                        : $query),

                'number' => Filter::make("cf_{$slug}")
                    ->label(__($name))
                    ->schema([
                        TextInput::make('min')->label(__('Min'))->numeric(),
                        TextInput::make('max')->label(__('Max'))->numeric(),
                    ])
                    ->query(function ($query, $data) use ($columnIdx) {
                        if (!empty($data['min'])) {
                            $query->whereRaw("CAST({$columnIdx} AS DECIMAL(15,4)) >= ?", [(float) $data['min']]);
                        }
                        if (!empty($data['max'])) {
                            $query->whereRaw("CAST({$columnIdx} AS DECIMAL(15,4)) <= ?", [(float) $data['max']]);
                        }
                        return $query;
                    }),

                'date' => Filter::make("cf_{$slug}")
                    ->label(__($name))
                    ->schema([
                        DatePicker::make('from')->label(__('From'))->native(false)->displayFormat('Y-m-d'),
                        DatePicker::make('to')->label(__('To'))->native(false)->displayFormat('Y-m-d'),
                    ])
                    ->query(function ($query, $data) use ($columnIdx) {
                        if (!empty($data['from'])) {
                            $query->where($columnIdx, '>=', $data['from']);
                        }
                        if (!empty($data['to'])) {
                            $query->where($columnIdx, '<=', $data['to']);
                        }
                        return $query;
                    }),

                default => Filter::make("cf_{$slug}")
                    ->label(__($name))
                    ->schema([
                        TextInput::make('value')->label(__($name))->placeholder(__("Search by {$name}")),
                    ])
                    ->query(fn ($query, $data) => !empty($data['value'])
                        ? $query->where($columnIdx, 'LIKE', "%{$data['value']}%")
                        : $query),
            };

            $filters[] = $filter;
        }

        return $filters;
    }
}
