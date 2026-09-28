<?php

namespace App\Policies;

use App\Models\Batch;
use App\Models\User;

/**
 * Tenant isolation for batches. Every controller that touches a batch must
 * authorize through this policy; frontend filtering is never trusted.
 */
class BatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isCompanyOwner() || $user->isSuperAdmin();
    }

    public function view(User $user, Batch $batch): bool
    {
        return $user->isSuperAdmin() || $user->belongsToCompany($batch->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isCompanyOwner() && ! $user->isBlocked();
    }

    public function update(User $user, Batch $batch): bool
    {
        return $user->isCompanyOwner() && $user->belongsToCompany($batch->company_id);
    }

    public function delete(User $user, Batch $batch): bool
    {
        return $user->isSuperAdmin() || $user->belongsToCompany($batch->company_id);
    }

    public function download(User $user, Batch $batch): bool
    {
        return $user->belongsToCompany($batch->company_id);
    }
}
