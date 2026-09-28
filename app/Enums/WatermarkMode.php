<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum WatermarkMode: string
{
    use HasValues;

    case None = 'none';
    case All = 'all';
    case Selected = 'selected';
}
