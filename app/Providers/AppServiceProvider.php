<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Document;
use App\Policies\CustomerPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // ── Policies ──────────────────────────────────────────────────────────
        Gate::policy(Customer::class, CustomerPolicy::class);

        // ── Super-admin bypasses ALL gates ────────────────────────────────────
        // This means super_admin can call $this->authorize() anywhere without
        // needing explicit policy methods for every model.
        // NOTE: The before() callback runs before any policy check.
        // Returning true here short-circuits all Gate/Policy checks for super_admin.
        // Spatie role middleware ('role:super_admin') is separate and still applies
        // to route-level protection.
        Gate::before(function ($user, $ability) {
            if ($user->isSuperAdmin()) {
                return true;
            }
        });
    }
}