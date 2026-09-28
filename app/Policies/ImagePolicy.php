<?php

namespace App\Policies;

use App\Models\Image;
use App\Models\User;

class ImagePolicy
{
    public function view(User $user, Image $image): bool
    {
        return $user->isSuperAdmin() || $user->belongsToCompany($image->company_id);
    }

    public function update(User $user, Image $image): bool
    {
        return $user->isCompanyOwner() && $user->belongsToCompany($image->company_id);
    }

    public function delete(User $user, Image $image): bool
    {
        return $this->update($user, $image);
    }

    public function download(User $user, Image $image): bool
    {
        return $user->belongsToCompany($image->company_id);
    }
}
