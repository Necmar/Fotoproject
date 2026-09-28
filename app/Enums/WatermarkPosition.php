<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum WatermarkPosition: string
{
    use HasValues;

    case TopLeft = 'top_left';
    case TopRight = 'top_right';
    case BottomLeft = 'bottom_left';
    case BottomRight = 'bottom_right';
    case Center = 'center';
}
