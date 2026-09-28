<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum BackgroundOption: string
{
    use HasValues;

    /** Default: keep the original background, only improve image quality. */
    case Keep = 'keep';
    case CleanSubtle = 'clean_subtle';
    case RemoveDistractions = 'remove_distractions';
    case BlurLight = 'blur_light';
    case Remove = 'remove';
    case Neutral = 'neutral';

    /** True when the AI is allowed to touch background pixels. */
    public function editsBackground(): bool
    {
        return $this !== self::Keep;
    }
}
