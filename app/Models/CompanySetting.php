<?php

namespace App\Models;

use App\Enums\AspectRatio;
use App\Enums\BackgroundOption;
use App\Enums\OptimizationStrength;
use App\Enums\OutputFormat;
use App\Enums\Resolution;
use App\Enums\WatermarkMode;
use App\Enums\WatermarkPosition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'default_output_format', 'default_resolution', 'default_aspect_ratio', 'default_strength',
    'default_background', 'default_watermark_mode', 'default_watermark_position',
    'default_watermark_opacity', 'filename_prefix',
])]
class CompanySetting extends Model
{
    protected function casts(): array
    {
        return [
            'default_output_format' => OutputFormat::class,
            'default_resolution' => Resolution::class,
            'default_aspect_ratio' => AspectRatio::class,
            'default_strength' => OptimizationStrength::class,
            'default_background' => BackgroundOption::class,
            'default_watermark_mode' => WatermarkMode::class,
            'default_watermark_position' => WatermarkPosition::class,
            'default_watermark_opacity' => 'integer',
        ];
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('bora.company_defaults');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
