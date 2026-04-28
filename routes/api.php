<?php
// routes/api.php

use App\Http\Controllers\GatewayController;
use Illuminate\Support\Facades\Route;

/**
 * Gateway webhook — unauthenticated by Laravel, authenticated by X-Gateway-Secret header.
 * Company/session is identified by session_id in the request body payload.
 * API routes in Laravel 11 are already CSRF-exempt.
 */
Route::post('/gateway/webhook', [GatewayController::class, 'webhook'])
    ->name('gateway.webhook');

Route::post('/gateway/upload-media', [GatewayController::class, 'uploadMedia']);