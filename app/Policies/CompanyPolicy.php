<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Company $company): bool
    {
        return $user->isSuperAdmin() || $user->belongsToCompany($company);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /** Owners update their own company settings; the Super Admin manages all. */
    public function update(User $user, Company $company): bool
    {
        return $user->isSuperAdmin() || ($user->belongsToCompany($company) && ! $company->isBlocked());
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->isSuperAdmin();
    }

    public function block(User $user, Company $company): bool
    {
        return $user->isSuperAdmin();
    }
}
