<?php

use Illuminate\Support\Facades\Route;
use JanDev\EmailSystem\Http\Controllers\UnsubscribeController;
use JanDev\EmailSystem\Http\Controllers\TrackingController;

Route::get('/unsubscribe', [UnsubscribeController::class, 'unsubscribe'])
    ->name('email-system.unsubscribe');

Route::get('/track/open/{log_id}', [TrackingController::class, 'trackOpen'])
    ->name('email-system.track.open')
    ->middleware('signed');
