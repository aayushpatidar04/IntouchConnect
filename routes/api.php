<?php

use App\Http\Controllers\GatewayController;
use Illuminate\Support\Facades\Route;

// Gateway webhook — no CSRF, authenticated by secret header only
// Old single-tenant webhook (keep for backwards compat)
Route::post('/gateway/webhook', [GatewayController::class, 'webhook'])
    ->name('gateway.webhook');

// New per-company webhook — each Gateway's CRM_URL points to /api/gateway/{company_id}/webhook
Route::post('/gateway/{company}/webhook', [GatewayController::class, 'companyWebhook'])
    ->name('gateway.company.webhook');