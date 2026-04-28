<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * CompanyScope — automatically filters all queries by the authenticated user's
 * company_id so that one tenant can never see another tenant's data.
 *
 * Super-admin (no company_id, role = super_admin) bypasses this scope
 * and can see all data across all companies.
 *
 * Applied to: Customer, Message, Document (via their booted() method).
 * NOT applied to: User (super-admin needs to query all users).
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Only filter when there is an authenticated session
        if (! auth()->check()) {
            return;
        }

        $user = auth()->user();

        // Super-admin sees everything — no filter applied
        if ($user->hasRole('super_admin')) {
            return;
        }

        // All other roles (admin, executive, auditor) are scoped to their company
        if ($user->company_id) {
            $builder->where($model->getTable() . '.company_id', $user->company_id);
        }
    }
}