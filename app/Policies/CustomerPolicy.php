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

        // Must be in the same company
        if ($user->company_id !== $customer->company_id) return false;

        // Company admin and auditor can view all customers in their company
        if ($user->hasAnyRole(['admin', 'auditor'])) return true;

        // Executive can only view their assigned customers
        return $customer->assigned_to === $user->id;
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