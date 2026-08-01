<?php

namespace JanDev\EmailSystem\Support;

use JanDev\EmailSystem\Support\Sms\SmsPhone;

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

        $header = ['name', 'email', 'phone', 'zerobounce_status'];

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

        // Order must match the export header exactly, or a re-import silently
        // loads every column into the wrong field.
        $row = [$user->name, $user->email, $user->phone ?? '', $user->zerobounce_status ?? 'unverified'];

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
     * Smart detection: determines if the first row is a header or data.
     *
     * @return array{headers: array<string>, separator: string, has_header: bool}|null
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

        if (empty($headers)) {
            return null;
        }

        $hasHeader = self::looksLikeHeader($headers);

        if (!$hasHeader) {
            // Show "Column N (value)" so user can identify columns without opening the CSV
            $rawValues = $headers;
            $headers = array_map(
                function (int $i) use ($rawValues) {
                    $preview = mb_strimwidth($rawValues[$i] ?? '', 0, 40, '…');
                    return 'Column ' . ($i + 1) . ' (' . $preview . ')';
                },
                array_keys($headers),
            );
        }

        return ['headers' => $headers, 'separator' => $separator, 'has_header' => $hasHeader];
    }

    /**
     * Determine if a row looks like a header (labels) or data.
     * Returns false if any cell looks like an email address, a number,
     * or a date — indicating the row contains actual data.
     *
     * @param  array<string> $cells
     */
    public static function looksLikeHeader(array $cells): bool
    {
        foreach ($cells as $cell) {
            $cell = trim($cell);
            if ($cell === '') {
                continue;
            }
            // Contains @ → likely an email address → data row
            if (filter_var($cell, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
            // Pure numeric value → data row
            if (is_numeric($cell) && strlen($cell) > 0) {
                return false;
            }
            // Date pattern (YYYY-MM-DD or DD/MM/YYYY or MM/DD/YYYY) → data row
            if (preg_match('/^\d{4}[-\/]\d{2}[-\/]\d{2}$/', $cell) || preg_match('/^\d{2}[-\/]\d{2}[-\/]\d{4}$/', $cell)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize a header string: lowercase, strip accents, replace separators with underscore.
     */
    public static function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        // Strip common accents
        $header = strtr($header, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ű' => 'u', 'ä' => 'a', 'ë' => 'e', 'ï' => 'i',
            'ô' => 'o', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c',
        ]);
        // Replace spaces, hyphens, dots with underscore
        $header = preg_replace('/[\s\-\.]+/', '_', $header);
        // Remove non-alphanumeric except underscore
        $header = preg_replace('/[^a-z0-9_]/', '', $header);
        // Collapse multiple underscores
        return preg_replace('/_+/', '_', trim($header, '_'));
    }

    /**
     * Auto-detect a column index from headers by matching aliases (case-insensitive).
     * Uses normalized matching: strips accents, replaces separators, then tries
     * exact match first, then contains match as fallback.
     * Columns already claimed (in $excludeIndices) are skipped.
     *
     * @param  array<string> $headers
     * @param  array<string> $aliases
     * @param  array<string> $excludeIndices  Column indices already mapped
     */
    public static function autoDetectColumn(array $headers, array $aliases, array $excludeIndices = []): ?string
    {
        $normalizedHeaders = [];
        foreach ($headers as $i => $header) {
            $normalizedHeaders[$i] = self::normalizeHeader($header);
        }

        $normalizedAliases = array_map([self::class, 'normalizeHeader'], $aliases);

        // Pass 1: exact match on normalized form
        foreach ($normalizedHeaders as $i => $nh) {
            if (in_array((string) $i, $excludeIndices, true)) {
                continue;
            }
            foreach ($normalizedAliases as $alias) {
                if ($nh === $alias) {
                    return (string) $i;
                }
            }
        }

        // Pass 2: contains match (header contains alias or alias contains header)
        foreach ($normalizedHeaders as $i => $nh) {
            if (in_array((string) $i, $excludeIndices, true)) {
                continue;
            }
            if ($nh === '' || strlen($nh) < 3) {
                continue;
            }
            foreach ($normalizedAliases as $alias) {
                if (strlen($alias) < 3) {
                    continue;
                }
                if (str_contains($nh, $alias) || str_contains($alias, $nh)) {
                    return (string) $i;
                }
            }
        }

        return null;
    }

    /**
     * Get country options for default country select (ISO 3166-1 alpha-2).
     *
     * @return array<string, string>
     */
    public static function getCountryOptions(): array
    {
        return [
            'GB' => 'United Kingdom',
            'US' => 'United States',
            'DE' => 'Germany',
            'FR' => 'France',
            'ES' => 'Spain',
            'IT' => 'Italy',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'AT' => 'Austria',
            'CH' => 'Switzerland',
            'IE' => 'Ireland',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'PT' => 'Portugal',
            'GR' => 'Greece',
            'PL' => 'Poland',
            'CZ' => 'Czech Republic',
            'SK' => 'Slovakia',
            'HU' => 'Hungary',
            'RO' => 'Romania',
            'BG' => 'Bulgaria',
            'HR' => 'Croatia',
            'SI' => 'Slovenia',
            'RS' => 'Serbia',
            'LT' => 'Lithuania',
            'LV' => 'Latvia',
            'EE' => 'Estonia',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'NZ' => 'New Zealand',
            'BR' => 'Brazil',
            'MX' => 'Mexico',
            'JP' => 'Japan',
            'IN' => 'India',
            'ZA' => 'South Africa',
            'AE' => 'UAE',
            'TR' => 'Turkey',
            'UA' => 'Ukraine',
        ];
    }

    /**
     * Get currency options for default currency select.
     *
     * @return array<string, string>
     */
    public static function getCurrencyOptions(): array
    {
        return [
            'GBP' => 'GBP — British Pound',
            'EUR' => 'EUR — Euro',
            'USD' => 'USD — US Dollar',
            'CAD' => 'CAD — Canadian Dollar',
            'AUD' => 'AUD — Australian Dollar',
            'NZD' => 'NZD — New Zealand Dollar',
            'SEK' => 'SEK — Swedish Krona',
            'NOK' => 'NOK — Norwegian Krone',
            'DKK' => 'DKK — Danish Krone',
            'CHF' => 'CHF — Swiss Franc',
            'PLN' => 'PLN — Polish Zloty',
            'CZK' => 'CZK — Czech Koruna',
            'HUF' => 'HUF — Hungarian Forint',
            'RON' => 'RON — Romanian Leu',
            'BGN' => 'BGN — Bulgarian Lev',
            'HRK' => 'HRK — Croatian Kuna',
            'TRY' => 'TRY — Turkish Lira',
            'BRL' => 'BRL — Brazilian Real',
            'MXN' => 'MXN — Mexican Peso',
            'JPY' => 'JPY — Japanese Yen',
            'INR' => 'INR — Indian Rupee',
            'ZAR' => 'ZAR — South African Rand',
            'AED' => 'AED — UAE Dirham',
            'UAH' => 'UAH — Ukrainian Hryvnia',
        ];
    }

    /**
     * Get the default currency for a country code (ISO 3166-1 alpha-2).
     */
    public static function currencyForCountry(string $countryCode): ?string
    {
        $map = [
            'GB' => 'GBP', 'IE' => 'GBP',
            'US' => 'USD',
            'CA' => 'CAD',
            'AU' => 'AUD',
            'NZ' => 'NZD',
            'JP' => 'JPY',
            'IN' => 'INR',
            'BR' => 'BRL',
            'MX' => 'MXN',
            'ZA' => 'ZAR',
            'AE' => 'AED',
            'CH' => 'CHF',
            'SE' => 'SEK',
            'NO' => 'NOK',
            'DK' => 'DKK',
            'PL' => 'PLN',
            'CZ' => 'CZK',
            'HU' => 'HUF',
            'RO' => 'RON',
            'BG' => 'BGN',
            'HR' => 'HRK',
            'TR' => 'TRY',
            'UA' => 'UAH',
            // Eurozone
            'DE' => 'EUR', 'FR' => 'EUR', 'ES' => 'EUR', 'IT' => 'EUR',
            'NL' => 'EUR', 'BE' => 'EUR', 'AT' => 'EUR', 'PT' => 'EUR',
            'GR' => 'EUR', 'FI' => 'EUR', 'SK' => 'EUR', 'SI' => 'EUR',
            'EE' => 'EUR', 'LV' => 'EUR', 'LT' => 'EUR',
        ];

        return $map[strtoupper($countryCode)] ?? null;
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

        $phone = null;

        foreach ($header as $i => $col) {
            if ($i < 2) {
                continue;
            }
            // `phone` is a real column, not a custom field: SMS campaigns filter and
            // suppress on it, and a value buried in the custom_fields JSON cannot be
            // indexed or matched against the suppression list.
            if ($col === 'phone') {
                $phone = SmsPhone::normalise(trim($row[$i] ?? ''));
                if ($phone === null && trim($row[$i] ?? '') !== '') {
                    $errors[] = "Unusable phone number: " . trim($row[$i] ?? '');
                }
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
            'phone'         => $phone,
            'custom_fields' => $customFields,
            'errors'        => $errors,
        ];
    }
}
