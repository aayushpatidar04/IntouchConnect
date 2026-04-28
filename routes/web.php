<?php
// routes/web.php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GatewayController;
use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;

// ─── Redirect root to dashboard ──────────────────────────────────────────────
Route::get('/', fn() => redirect()->route('dashboard'));

// ─── All authenticated routes ─────────────────────────────────────────────────
Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ── Customers ─────────────────────────────────────────────────────────────
    Route::resource('customers', CustomerController::class)->except(['edit', 'create']);

    // ── Messages & Documents (nested under customer) ──────────────────────────
    Route::prefix('customers/{customer}')->group(function () {
        Route::post('/messages',            [MessageController::class, 'send'])->name('messages.send');
        Route::get('/messages/history',     [MessageController::class, 'history'])->name('messages.history');
        Route::post('/messages/mark-read',  [MessageController::class, 'markRead'])->name('messages.mark-read');

        Route::post('/documents',                        [DocumentController::class, 'upload'])->name('documents.upload');
        Route::post('/documents/{document}/send',        [DocumentController::class, 'sendToCustomer'])->name('documents.send');
    });

    Route::get('/documents/{document}/download',        [DocumentController::class, 'download'])->name('documents.download');
    Route::patch('/documents/{document}/status',        [DocumentController::class, 'updateStatus'])->name('documents.status');
    Route::delete('/documents/{document}',              [DocumentController::class, 'destroy'])->name('documents.destroy');

    // ── Company-admin panel (admin + super_admin) ─────────────────────────────
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/users',              [AdminController::class, 'users'])->name('users');
        Route::post('/users',             [AdminController::class, 'storeUser'])->name('users.store');
        Route::patch('/users/{user}',     [AdminController::class, 'updateUser'])->name('users.update');
        Route::delete('/users/{user}',    [AdminController::class, 'destroyUser'])->name('users.destroy');
        Route::get('/audit-logs',         [AdminController::class, 'auditLogs'])->name('audit-logs');
    });

    // ── WhatsApp gateway control ──────────────────────────────────────────────
    Route::prefix('gateway')->name('gateway.')->group(function () {
        Route::get('/status',             [GatewayController::class, 'status'])->name('status');
        Route::get('/queue/stats',        [GatewayController::class, 'queueStats'])->name('queue-stats');
        Route::post('/logout',            [GatewayController::class, 'logout'])->name('logout');
        Route::post('/session/create',    [GatewayController::class, 'createSession'])->name('session.create');
    });

    // ── Super-admin panel ─────────────────────────────────────────────────────
    // Manages all companies, their admins, and global settings.
    Route::prefix('superadmin')->name('superadmin.')->group(function () {
        Route::resource('companies', CompanyController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy'])
            ->names([
                'index'   => 'companies.index',
                'store'   => 'companies.store',
                'show'    => 'companies.show',
                'update'  => 'companies.update',
                'destroy' => 'companies.destroy',
            ]);

        Route::post('/companies/{company}/provision-session',
            [CompanyController::class, 'provisionSession']
        )->name('companies.provision-session');
    });
});

// ─── Breeze Auth Routes ───────────────────────────────────────────────────────
require __DIR__ . '/auth.php';