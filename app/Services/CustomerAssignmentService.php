<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;

class CustomerAssignmentService
{
    /**
     * Assign an executive round-robin.
     * For normal companies: round-robin within that company's executives.
     * For Arihant: round-robin across ALL executives from ALL companies,
     * and return the executive's company_id for customer assignment.
     */
    public function assignExecutive(?Company $company, bool $isArihant = false): ?User
    {
        if ($isArihant) {
            return $this->assignArihantExecutive();
        }

        if (!$company) {
            return null;
        }

        return $this->assignCompanyExecutive($company);
    }

    /**
     * Round-robin within a single company's executives.
     */
    private function assignCompanyExecutive(Company $company): ?User
    {
        $executives = User::query()
            ->where('company_id', $company->id)
            ->whereHas('roles', function ($q) {
                $q->where('name', 'executive');
            })
            ->orderBy('id')
            ->get();

        if ($executives->isEmpty()) {
            return User::query()
                ->where('company_id', $company->id)
                ->whereHas('roles', function ($q) {
                    $q->where('name', 'admin');
                })
                ->first();
        }

        if (!$company->last_assigned_executive_id) {
            $executive = $executives->first();
            $company->update(['last_assigned_executive_id' => $executive->id]);
            return $executive;
        }

        $currentIndex = $executives->search(function ($user) use ($company) {
            return $user->id == $company->last_assigned_executive_id;
        });

        if ($currentIndex === false) {
            $executive = $executives->first();
            $company->update(['last_assigned_executive_id' => $executive->id]);
            return $executive;
        }

        $nextIndex = ($currentIndex + 1) % $executives->count();
        $executive = $executives[$nextIndex];

        $company->update(['last_assigned_executive_id' => $executive->id]);

        return $executive;
    }

    /**
     * Round-robin across ALL executives from ALL companies.
     * Uses the Arihant company record to track last assigned.
     */
    private function assignArihantExecutive(): ?User
    {
        $executives = User::query()
            ->whereHas('roles', function ($q) {
                $q->where('name', 'executive');
            })
            ->orderBy('id')
            ->get();

        if ($executives->isEmpty()) {
            return User::query()
                ->whereHas('roles', function ($q) {
                    $q->where('name', 'admin');
                })
                ->orderBy('id')
                ->first();
        }

        $arihantCompany = Company::withoutGlobalScopes()
            ->whereRaw('LOWER(name) = ?', ['arihant special session'])
            ->first();

        if (!$arihantCompany) {
            return $executives->first();
        }

        if (!$arihantCompany->last_assigned_executive_id) {
            $executive = $executives->first();
            $arihantCompany->update(['last_assigned_executive_id' => $executive->id]);
            return $executive;
        }

        $currentIndex = $executives->search(function ($user) use ($arihantCompany) {
            return $user->id == $arihantCompany->last_assigned_executive_id;
        });

        if ($currentIndex === false) {
            $executive = $executives->first();
            $arihantCompany->update(['last_assigned_executive_id' => $executive->id]);
            return $executive;
        }

        $nextIndex = ($currentIndex + 1) % $executives->count();
        $executive = $executives[$nextIndex];

        $arihantCompany->update(['last_assigned_executive_id' => $executive->id]);

        return $executive;
    }
}
