<?php

namespace JanDev\EmailSystem\Support;

use JanDev\EmailSystem\Models\AudienceUser;

class CsvHelper
{
    /**
     * Build the CSV header row including custom field slugs.
     *
     * @return array<string>
     */
    public static function buildHeader(): array
    {
        $definitions = AudienceUser::getCustomFieldDefinitions();

        $header = ['name', 'email'];

        foreach ($definitions as $field) {
            $slug = $field['slug'] ?? null;
            if ($slug && preg_match('/^[a-zA-Z0-9_]+$/', $slug)) {
                $header[] = $slug;
            }
        }

        return $header;
    }

    /**
     * Build a CSV data row for an AudienceUser.
     *
     * @return array<string>
     */
    public static function buildRow(AudienceUser $user, ?array $definitions = null): array
    {
        $definitions = $definitions ?? AudienceUser::getCustomFieldDefinitions();

        $row = [$user->name, $user->email];

        foreach ($definitions as $field) {
            $slug = $field['slug'] ?? null;
            $type = $field['type'] ?? 'text';

            if (!$slug || !preg_match('/^[a-zA-Z0-9_]+$/', $slug)) {
                continue;
            }

            $value = $user->getCustomFieldValue($slug);

            if ($type === 'boolean') {
                $row[] = $value ? '1' : '0';
            } else {
                $row[] = (string) ($value ?? '');
            }
        }

        return $row;
    }

    /**
     * Validate that the CSV header has 'name' as first and 'email' as second element.
     *
     * @param  array<string> $header
     */
    public static function isValidHeader(array $header): bool
    {
        return isset($header[0], $header[1])
            && $header[0] === 'name'
            && $header[1] === 'email';
    }

    /**
     * Detect CSV headers and separator from a file path.
     * Handles UTF-8 BOM and auto-detects ; vs , separator.
     *
     * @return array{headers: array<string>, separator: string}|null
     */
    public static function detectHeaders(string $filePath): ?array
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return null;
        }

        $firstLine = fgets($handle);
        fclose($handle);

        if (!$firstLine) {
            return null;
        }

        $firstLine = trim(preg_replace('/^\xEF\xBB\xBF/', '', $firstLine));

        $semicolons = substr_count($firstLine, ';');
        $commas = substr_count($firstLine, ',');
        $separator = $semicolons >= $commas ? ';' : ',';

        $headers = array_map('trim', str_getcsv($firstLine, $separator));

        return empty($headers) ? null : ['headers' => $headers, 'separator' => $separator];
    }

    /**
     * Auto-detect a column index from headers by matching aliases (case-insensitive).
     *
     * @param  array<string> $headers
     * @param  array<string> $aliases
     */
    public static function autoDetectColumn(array $headers, array $aliases): ?string
    {
        foreach ($headers as $i => $header) {
            $normalized = strtolower(trim($header));
            foreach ($aliases as $alias) {
                if ($normalized === strtolower($alias)) {
                    return (string) $i;
                }
            }
        }
        return null;
    }

    /**
     * Parse and validate a single field value by type.
     *
     * @return array{value: mixed, error: string|null}
     */
    public static function parseFieldValue(string $value, string $slug, string $type): array
    {
        switch ($type) {
            case 'number':
                if (!is_numeric($value)) {
                    return ['value' => null, 'error' => "Invalid number for '{$slug}': {$value}"];
                }
                return ['value' => $value + 0, 'error' => null];
            case 'boolean':
                if (!in_array($value, ['0', '1', 'true', 'false'], true)) {
                    return ['value' => null, 'error' => "Invalid boolean for '{$slug}': {$value}"];
                }
                return ['value' => in_array($value, ['1', 'true'], true), 'error' => null];
            case 'date':
                $date = \DateTime::createFromFormat('Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    return ['value' => null, 'error' => "Invalid date for '{$slug}': {$value}"];
                }
                return ['value' => $value, 'error' => null];
            default:
                return ['value' => $value, 'error' => null];
        }
    }

    /**
     * Parse a CSV data row using the CSV header to extract name, email, and custom fields.
     * Validates types: number must be numeric, boolean must be 0/1/true/false, date must be Y-m-d.
     * Invalid values are skipped (not added to custom_fields) and reported via $errors.
     *
     * @param  array<string> $header
     * @param  array<string> $row
     * @return array{name: string, email: string, custom_fields: array<string, mixed>, errors: array<string>}
     */
    public static function parseRow(array $header, array $row): array
    {
        $definitions = AudienceUser::getCustomFieldDefinitions();
        $defBySlug = collect($definitions)
            ->filter(fn ($f) => isset($f['slug']) && preg_match('/^[a-zA-Z0-9_]+$/', $f['slug']))
            ->keyBy('slug')
            ->all();

        $name  = $row[0] ?? '';
        $email = $row[1] ?? '';
        $customFields = [];
        $errors = [];

        foreach ($header as $i => $col) {
            if ($i < 2) {
                continue;
            }
            if (!isset($defBySlug[$col])) {
                continue;
            }

            $value = trim($row[$i] ?? '');
            if ($value === '') {
                continue;
            }

            $type = $defBySlug[$col]['type'] ?? 'text';

            switch ($type) {
                case 'number':
                    if (!is_numeric($value)) {
                        $errors[] = "Invalid number for '{$col}': {$value}";
                        continue 2;
                    }
                    $customFields[$col] = $value + 0;
                    break;
                case 'boolean':
                    if (!in_array($value, ['0', '1', 'true', 'false'], true)) {
                        $errors[] = "Invalid boolean for '{$col}': {$value}";
                        continue 2;
                    }
                    $customFields[$col] = in_array($value, ['1', 'true'], true);
                    break;
                case 'date':
                    $date = \DateTime::createFromFormat('Y-m-d', $value);
                    if (!$date || $date->format('Y-m-d') !== $value) {
                        $errors[] = "Invalid date for '{$col}': {$value}";
                        continue 2;
                    }
                    $customFields[$col] = $value;
                    break;
                default:
                    $customFields[$col] = $value;
                    break;
            }
        }

        return [
            'name'          => $name,
            'email'         => $email,
            'custom_fields' => $customFields,
            'errors'        => $errors,
        ];
    }
}
