<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        // Super-admin can view everything
        if ($user->isSuperAdmin()) return true;

        // Company admin and auditor can view all customers in their company
        if ($user->hasAnyRole(['admin', 'auditor'])) {
            return $user->company_id === $customer->company_id;
        }

        // Executive: can view if assigned_to me (same company check)
        if ($customer->assigned_to === $user->id) {
            return $user->company_id === $customer->company_id;
        }

        // Executive: can view if I'm the old owner (any company allowed)
        if ($customer->old_owner_id === $user->id) {
            return true;
        }

        return false;
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
