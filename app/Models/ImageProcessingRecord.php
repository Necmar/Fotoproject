<?php

namespace App\Models;

use App\Enums\ProcessingType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One unit of processing work (AI analysis, AI edit, local processing).
 * Survives image cleanup (image_id/batch_id become null) for usage statistics.
 */
#[Fillable([
    'company_id', 'batch_id', 'image_id', 'type', 'status', 'provider', 'model', 'settings',
    'input_tokens', 'output_tokens', 'estimated_cost_usd', 'duration_ms', 'attempt',
    'error_code', 'error_message',
])]
class ImageProcessingRecord extends Model
{
    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_STARTED = 'started';

    protected function casts(): array
    {
        return [
            'type' => ProcessingType::class,
            'settings' => 'array',
            'estimated_cost_usd' => 'decimal:5',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Image, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
