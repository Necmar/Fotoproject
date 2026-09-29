<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum CompanyStatus: string
{
    use HasValues;

    case Active = 'active';
    case Blocked = 'blocked';
}
