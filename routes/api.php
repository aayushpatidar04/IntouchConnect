<?php

use App\Http\Controllers\GatewayController;
use Illuminate\Support\Facades\Route;

// Gateway webhook — no CSRF, authenticated by secret header only
Route::post('/gateway/webhook', [GatewayController::class, 'webhook'])
    ->name('gateway.webhook')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);