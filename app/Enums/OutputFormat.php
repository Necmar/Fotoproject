<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

enum OutputFormat: string
{
    use HasValues;

    case Jpg = 'jpg';
    case Png = 'png';

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Jpg => 'image/jpeg',
            self::Png => 'image/png',
        };
    }
}
