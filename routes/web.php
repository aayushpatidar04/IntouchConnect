<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GatewayController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

// ─── Redirect root to dashboard ──────────────────────────────────────────────
Route::get('/', fn() => redirect()->route('dashboard'));

// ─── All authenticated routes ─────────────────────────────────────────────────
Route::middleware(['auth', 'verified', 'company.active'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/api/unread-messages', [DashboardController::class, 'unreadMessages'])->name('api.unread-messages');

    // Analytics — admin and auditor only (role check is inside the controller)
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

    // ── Customers ─────────────────────────────────────────────────────────────
    Route::resource('customers', CustomerController::class)->except(['edit', 'create']);
    Route::post('/customers/fetch-bitrix-lead', [CustomerController::class, 'fetchBitrixLead'])->name('customers.fetch-bitrix-lead');
    Route::get('customers-list', [CustomerController::class, 'list'])->name('customers.list');
    Route::get('groups-list', [GroupController::class, 'list'])->name('groups.list');

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

    // ═══════════════════════════════════════════════════════════════════════
    //  TEMPLATES — Global (superadmin CRUD) + Company assignments + Executive use
    // ═══════════════════════════════════════════════════════════════════════

    // Executive/Admin: List & send templates (global templates they can access)
    Route::prefix('templates')->name('templates.')->group(function () {
        Route::get('/', [TemplateController::class, 'index'])->name('index');
        Route::get('/broadcasts/history', [TemplateController::class, 'broadcastHistory'])->name('broadcasts.history');
        Route::get('/broadcasts/{broadcast}', [TemplateController::class, 'broadcastShow'])->name('broadcasts.show');
        Route::get('/{template}/show', [TemplateController::class, 'show'])->name('show');
        Route::post('/{template}/preview', [TemplateController::class, 'preview'])->name('preview');
        Route::post('/{template}/broadcast', [TemplateController::class, 'broadcast'])->name('broadcast');
    });

    // Company Admin: Manage executive assignments to global templates
    Route::prefix('admin/templates')->name('admin.templates.')->middleware('role:admin')->group(function () {
        Route::get('/assignments', [TemplateController::class, 'companyAssignments'])->name('assignments');
        Route::post('/{template}/assignments', [TemplateController::class, 'updateAssignments'])->name('assignments.update');
    });

    // ── Groups ────────────────────────────────────────────────────────────────
    Route::prefix('groups')->name('groups.')->group(function () {
        Route::get('/', [GroupController::class, 'index'])->name('index');
        Route::post('/', [GroupController::class, 'store'])->name('store');
        Route::patch('/{group}', [GroupController::class, 'update'])->name('update');
        Route::delete('/{group}', [GroupController::class, 'destroy'])->name('destroy');
    });

    // ── Company-admin panel ───────────────────────────────────────────────────
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
        Route::patch('/users/{user}', [AdminController::class, 'updateUser'])->name('users.update');
	Route::post('/users/{user}/switch-company', [AdminController::class, 'switchUserCompany'])->name('users.switch-company');
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
    Route::prefix('superadmin')
        ->name('superadmin.')
        ->middleware('role:super_admin')
        ->group(function () {

            // Companies CRUD
            Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
            Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
            Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
            Route::patch('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
            Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
            Route::post('/companies/{company}/toggle', [CompanyController::class, 'toggleActive'])->name('companies.toggle');
            Route::post('/companies/{company}/provision-session', [CompanyController::class, 'provisionSession'])->name('companies.provision-session');
            Route::post('/companies/{company}/logout-session', [CompanyController::class, 'logoutSession'])->name('companies.logout-session');
            Route::post('/companies/{company}/admins', [CompanyController::class, 'storeAdmin'])->name('companies.admins.store');
            Route::patch('/companies/{company}/admins/{user}', [CompanyController::class, 'updateAdmin'])->name('companies.admins.update');
            Route::delete('/companies/{company}/admins/{user}', [CompanyController::class, 'destroyAdmin'])->name('companies.admins.destroy');
            Route::get('/companies/{company}/available-admins', [CompanyController::class, 'availableAdmins'])->name('companies.available-admins');
            Route::get('/bitrix-departments', [CompanyController::class, 'bitrixDepartments'])->name('bitrix-departments');
            Route::get('/bitrix-agents', [CompanyController::class, 'bitrixAgents'])->name('bitrix-agents');
            Route::get('/bitrix-lead/{id}', [CompanyController::class, 'bitrixLead'])->name('bitrix-lead');

            // ═════════════════════════════════════════════════════════════════
            //  GLOBAL TEMPLATES — Superadmin CRUD
            // ═════════════════════════════════════════════════════════════════
            Route::prefix('templates')->name('templates.')->group(function () {
                Route::get('/', [TemplateController::class, 'adminIndex'])->name('index');
                Route::post('/', [TemplateController::class, 'adminStore'])->name('store');
                Route::patch('/{template}', [TemplateController::class, 'adminUpdate'])->name('update');
                Route::delete('/{template}', [TemplateController::class, 'adminDestroy'])->name('destroy');
            });
        });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('/switch-company', [CompanyController::class, 'switchCompany'])->name('switch-company');
});

require __DIR__ . '/auth.php';
