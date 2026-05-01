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
Route::middleware(['auth', 'verified', 'company.active'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ── Customers ─────────────────────────────────────────────────────────────
    Route::resource('customers', CustomerController::class)->except(['edit', 'create']);

    // ── Messages & Documents (nested under customer) ──────────────────────────
    Route::prefix('customers/{customer}')->group(function () {
        Route::post('/messages', [MessageController::class, 'send'])->name('messages.send');
        Route::get('/messages/history', [MessageController::class, 'history'])->name('messages.history');
        Route::post('/messages/mark-read', [MessageController::class, 'markRead'])->name('messages.mark-read');

        Route::post('/documents', [DocumentController::class, 'upload'])->name('documents.upload');
        Route::post('/documents/{document}/send', [DocumentController::class, 'sendToCustomer'])->name('documents.send');
    });

    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::patch('/documents/{document}/status', [DocumentController::class, 'updateStatus'])->name('documents.status');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    // ── Company-admin panel (admin + super_admin) ─────────────────────────────
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
        Route::patch('/users/{user}', [AdminController::class, 'updateUser'])->name('users.update');
        Route::delete('/users/{user}', [AdminController::class, 'destroyUser'])->name('users.destroy');
        Route::get('/audit-logs', [AdminController::class, 'auditLogs'])->name('audit-logs');
    });

    // ── WhatsApp gateway control ──────────────────────────────────────────────
    Route::prefix('gateway')->name('gateway.')->group(function () {
        Route::get('/status', [GatewayController::class, 'status'])->name('status');
        Route::get('/queue/stats', [GatewayController::class, 'queueStats'])->name('queue-stats');
        Route::post('/logout', [GatewayController::class, 'logout'])->name('logout');
        Route::post('/session/create', [GatewayController::class, 'createSession'])->name('session.create');
    });

    // ── Super-admin panel ─────────────────────────────────────────────────────
    // Manages all companies, their admins, and global settings.
    Route::prefix('superadmin')
        ->name('superadmin.')
        ->middleware('role:super_admin')   // hard gate — 403 for everyone else
        ->group(function () {

            // Companies CRUD
            Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
            Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
            Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
            Route::patch('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
            Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');

            // Company active/inactive toggle
            Route::post('/companies/{company}/toggle', [CompanyController::class, 'toggleActive'])->name('companies.toggle');

            // Gateway session management per company
            Route::post('/companies/{company}/provision-session', [CompanyController::class, 'provisionSession'])->name('companies.provision-session');
            Route::post('/companies/{company}/logout-session', [CompanyController::class, 'logoutSession'])->name('companies.logout-session');

            // Company admin user management (super-admin manages admins only)
            Route::post('/companies/{company}/admins', [CompanyController::class, 'storeAdmin'])->name('companies.admins.store');
            Route::patch('/companies/{company}/admins/{user}', [CompanyController::class, 'updateAdmin'])->name('companies.admins.update');
            Route::delete('/companies/{company}/admins/{user}', [CompanyController::class, 'destroyAdmin'])->name('companies.admins.destroy');
        });
});

// ─── Breeze Auth Routes ───────────────────────────────────────────────────────
require __DIR__ . '/auth.php';