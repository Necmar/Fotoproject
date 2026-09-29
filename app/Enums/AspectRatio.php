<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum AspectRatio: string
{
    use HasValues;

    case Original = 'original';
    case Square = '1:1';
    case FourThree = '4:3';
    case ThreeTwo = '3:2';
    case SixteenNine = '16:9';

    /** Width divided by height, or null to keep the original ratio. */
    public function ratio(): ?float
    {
        if ($this === self::Original) {
            return null;
        }

        [$w, $h] = array_map('intval', explode(':', $this->value));

        return $w / $h;
    }

    protected static function translationGroup(): string
    {
        return 'aspect_ratio';
    }
}
