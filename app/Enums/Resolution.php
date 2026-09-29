<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Longest side of the output image in pixels. */
enum Resolution: string
{
    use HasValues;

    case Compact = '1600';
    case Standard = '2000';
    case High = '2560';

    public function pixels(): int
    {
        return (int) $this->value;
    }
}
