<?php

use Illuminate\Support\Facades\Route;
use JanDev\EmailSystem\Http\Controllers\MailgunWebhookController;
use JanDev\EmailSystem\Http\Controllers\PmtaBounceController;

Route::post('/webhook/mailgun', [MailgunWebhookController::class, 'handle'])
    ->name('email-system.webhook.mailgun');

Route::post('/webhook/pmta-bounce', [PmtaBounceController::class, 'handle'])
    ->name('email-system.webhook.pmta-bounce');
