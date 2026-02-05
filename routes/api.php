<?php

use Illuminate\Support\Facades\Route;
use JanDev\EmailSystem\Http\Controllers\MailgunWebhookController;

Route::post('/webhook/mailgun', [MailgunWebhookController::class, 'handle'])
    ->name('email-system.webhook.mailgun');
