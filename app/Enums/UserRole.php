<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum UserRole: string
{
    use HasValues;

    case SuperAdmin = 'super_admin';
    case CompanyOwner = 'company_owner';
}
