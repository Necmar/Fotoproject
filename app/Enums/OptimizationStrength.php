<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum OptimizationStrength: string
{
    use HasValues;

    case Subtle = 'subtle';
    case Normal = 'normal';
    case Strong = 'strong';
}
