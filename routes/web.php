<?php

use Illuminate\Support\Facades\Route;
use JanDev\EmailSystem\Http\Controllers\CampaignPreviewController;
use JanDev\EmailSystem\Http\Controllers\UnsubscribeController;
use JanDev\EmailSystem\Http\Controllers\TrackingController;

Route::get('/unsubscribe', [UnsubscribeController::class, 'unsubscribe'])
    ->name('email-system.unsubscribe');

Route::get('/track/open/{log_id}', [TrackingController::class, 'trackOpen'])
    ->name('email-system.track.open')
    ->middleware('signed');

Route::get('/track/click/{log_id}', [TrackingController::class, 'trackClick'])
    ->name('email-system.track.click')
    ->middleware('signed');

Route::get('/campaign/{id}/preview', [CampaignPreviewController::class, 'preview'])
    ->name('email-system.campaign.preview')
    ->middleware(['web', 'auth']);
