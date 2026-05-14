<?php

namespace JanDev\EmailSystem\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal Telegram Bot API sender. Reads bot_token and chat_id from
 * email-system.telegram config. Used by scheduled commands (e.g. the bounce
 * summary) to push aggregated notifications.
 *
 * Never throws — failure paths log and return false so the caller (scheduler)
 * is not interrupted.
 */
class TelegramNotifier
{
    public function isEnabled(): bool
    {
        return (bool) config('email-system.telegram.enabled', false);
    }

    public function isConfigured(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $token = (string) config('email-system.telegram.bot_token', '');
        $chatId = (string) config('email-system.telegram.chat_id', '');

        return $token !== '' && $chatId !== '';
    }

    public function send(string $message, string $parseMode = 'HTML'): bool
    {
        if (!$this->isEnabled()) {
            Log::info('Telegram notifier disabled — skipping send');
            return false;
        }

        $token = (string) config('email-system.telegram.bot_token', '');
        $chatId = (string) config('email-system.telegram.chat_id', '');

        if ($token === '' || $chatId === '') {
            Log::warning('Telegram not configured — bot_token or chat_id is empty');
            return false;
        }

        try {
            $response = Http::retry(3, 5000, throw: false)
                ->timeout(10)
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => $parseMode,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Telegram send failed (HTTP ' . $response->status() . ')', [
                'body' => substr((string) $response->body(), 0, 500),
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::warning('Telegram send failed (exception): ' . $e->getMessage());
            return false;
        }
    }
}
