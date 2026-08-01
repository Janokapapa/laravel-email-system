<?php

use Illuminate\Support\Facades\Route;
use JanDev\EmailSystem\Http\Controllers\MailgunWebhookController;
use JanDev\EmailSystem\Http\Controllers\PmtaBounceController;
use JanDev\EmailSystem\Http\Controllers\PmtaBounceCountersController;
use JanDev\EmailSystem\Http\Controllers\PmtaStatsController;
use JanDev\EmailSystem\Http\Controllers\SmsInboundController;
use JanDev\EmailSystem\Http\Controllers\Api\AudienceGroupController;
use JanDev\EmailSystem\Http\Controllers\Api\AudienceUserController;
use JanDev\EmailSystem\Http\Middleware\ApiKeyAuth;

// Webhook routes (no API key auth — use their own inline auth)
Route::post('/webhook/mailgun', [MailgunWebhookController::class, 'handle'])
    ->name('email-system.webhook.mailgun');

Route::post('/webhook/pmta-bounce', [PmtaBounceController::class, 'handle'])
    ->name('email-system.webhook.pmta-bounce');

Route::post('/webhook/pmta-bounce-counters', [PmtaBounceCountersController::class, 'handle'])
    ->name('email-system.webhook.pmta-bounce-counters');

Route::post('/webhook/pmta-stats', [PmtaStatsController::class, 'handle'])
    ->name('email-system.webhook.pmta-stats');

// Inbound SMS. The MO endpoint carries opt-outs, so it must stay reachable:
// a STOP that cannot be delivered to us is an opt-out we never honour.
Route::post('/webhook/sms-mo', [SmsInboundController::class, 'mo'])
    ->name('email-system.webhook.sms-mo');

Route::post('/webhook/sms-dr', [SmsInboundController::class, 'dr'])
    ->name('email-system.webhook.sms-dr');

// Audience REST API v1 — requires valid X-API-Key header
Route::prefix('api/v1')
    ->middleware([ApiKeyAuth::class, 'throttle:60,1'])
    ->group(function () {
        // Audience Groups
        Route::get('/audience-groups', [AudienceGroupController::class, 'index'])
            ->name('email-system.api.audience-groups.index');
        Route::post('/audience-groups', [AudienceGroupController::class, 'store'])
            ->name('email-system.api.audience-groups.store');
        Route::get('/audience-groups/{id}', [AudienceGroupController::class, 'show'])
            ->name('email-system.api.audience-groups.show');
        Route::delete('/audience-groups/{id}', [AudienceGroupController::class, 'destroy'])
            ->name('email-system.api.audience-groups.destroy');

        // Audience Users (subscribers within a group)
        Route::get('/audience-groups/{group}/subscribers', [AudienceUserController::class, 'index'])
            ->name('email-system.api.subscribers.index');
        Route::post('/audience-groups/{group}/subscribers', [AudienceUserController::class, 'store'])
            ->name('email-system.api.subscribers.store');
        Route::put('/audience-groups/{group}/subscribers/{id}', [AudienceUserController::class, 'update'])
            ->name('email-system.api.subscribers.update');
        Route::delete('/audience-groups/{group}/subscribers/{id}', [AudienceUserController::class, 'destroy'])
            ->name('email-system.api.subscribers.destroy');
        Route::post('/audience-groups/{group}/subscribers/batch', [AudienceUserController::class, 'batch'])
            ->name('email-system.api.subscribers.batch');
    });
