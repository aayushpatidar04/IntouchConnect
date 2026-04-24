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
        if ($user->hasRole('admin') || $user->hasRole('auditor')) {
            return true;
        }

        return $customer->assigned_to === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'executive']);
    }

    public function update(User $user, Customer $customer): bool
    {
        if ($user->hasRole('admin')) return true;
        return $customer->assigned_to === $user->id;
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->hasRole('admin');
    }
}