<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Scopes\CompanyScope;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(
        User $user,
        Customer $customer
    ): bool {
        return Customer::withoutGlobalScope(CompanyScope::class)
            ->visibleTo($user)
            ->whereKey($customer->id)
            ->exists();
    }
    
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'executive']);
    }

    public function update(User $user, Customer $customer): bool
    {
        if ($user->isSuperAdmin()) return true;
        if ($user->company_id !== $customer->company_id) return false;
        if ($user->hasRole('admin')) return true;
        return $customer->assigned_to === $user->id;
    }

    public function delete(User $user, Customer $customer): bool
    {
        if ($user->isSuperAdmin()) return true;
        if ($user->company_id !== $customer->company_id) return false;
        return $user->hasRole('admin');
    }
}
