<?php

use Illuminate\Support\Facades\Route;
use JanDev\EmailSystem\Http\Controllers\MailgunWebhookController;
use JanDev\EmailSystem\Http\Controllers\PmtaBounceController;
use JanDev\EmailSystem\Http\Controllers\PmtaStatsController;

Route::post('/webhook/mailgun', [MailgunWebhookController::class, 'handle'])
    ->name('email-system.webhook.mailgun');

Route::post('/webhook/pmta-bounce', [PmtaBounceController::class, 'handle'])
    ->name('email-system.webhook.pmta-bounce');

Route::post('/webhook/pmta-stats', [PmtaStatsController::class, 'handle'])
    ->name('email-system.webhook.pmta-stats');
