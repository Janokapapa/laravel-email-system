<?php

namespace JanDev\EmailSystem\Support;

use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class ContentTypeConverter
{
    /**
     * Convert HTML body to plain text.
     */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = preg_replace('/<\/(p|div|h[1-6])>/i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');

        return trim($text);
    }

    /**
     * Convert plain text to basic HTML (newlines → <br>).
     */
    public static function textToHtml(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Handle content_type switch: convert body + cache original HTML for restore.
     *
     * Call from content_type Select's afterStateUpdated callback.
     */
    public static function handleContentTypeSwitch(Get $get, Set $set, ?string $old, ?string $new): void
    {
        if ($old === $new) {
            return;
        }

        $body = $get('body') ?? '';

        // Switching TO text from html/both
        if ($new === 'text' && $old !== 'text') {
            $set('_html_body_cache', $body);
            $plainText = static::htmlToText($body);
            $set('_text_body_cache', $plainText);
            $set('body', $plainText);

            static::convertVariations($get, $set, 'toText');
        }

        // Switching FROM text to html/both
        if ($new !== 'text' && $old === 'text') {
            $cachedHtml = $get('_html_body_cache') ?? '';
            $cachedText = $get('_text_body_cache') ?? '';

            if ($cachedHtml !== '' && $body === $cachedText) {
                // Text wasn't modified → restore original HTML
                $set('body', $cachedHtml);
            } elseif ($body !== '') {
                // Text was modified → convert to basic HTML
                $set('body', static::textToHtml($body));
            }

            $set('_html_body_cache', '');
            $set('_text_body_cache', '');

            static::convertVariations($get, $set, 'toHtml');
        }
    }

    private static function convertVariations(Get $get, Set $set, string $direction): void
    {
        $variations = $get('variations') ?? [];

        foreach (array_keys($variations) as $key) {
            $varBody = $variations[$key]['body'] ?? '';

            if ($direction === 'toText') {
                $set("variations.{$key}._html_body_cache", $varBody);
                $plainText = static::htmlToText($varBody);
                $set("variations.{$key}._text_body_cache", $plainText);
                $set("variations.{$key}.body", $plainText);
            } else {
                $cachedHtml = $variations[$key]['_html_body_cache'] ?? '';
                $cachedText = $variations[$key]['_text_body_cache'] ?? '';

                if ($cachedHtml !== '' && $varBody === $cachedText) {
                    $set("variations.{$key}.body", $cachedHtml);
                } elseif ($varBody !== '') {
                    $set("variations.{$key}.body", static::textToHtml($varBody));
                }

                $set("variations.{$key}._html_body_cache", '');
                $set("variations.{$key}._text_body_cache", '');
            }
        }
    }
}
