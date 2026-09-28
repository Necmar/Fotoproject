<?php

namespace App\Models;

use App\Enums\ImageStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'batch_id', 'company_id', 'position', 'original_filename', 'original_path', 'original_mime',
    'original_size', 'width', 'height', 'working_path', 'optimized_path', 'thumbnail_path',
    'output_filename', 'output_size', 'output_width', 'output_height', 'status', 'ai_status',
    'analysis', 'warnings', 'settings_override', 'apply_watermark', 'perceptual_hash',
    'content_hash', 'duplicate_of_id', 'error_code', 'error_message', 'attempts',
    'processing_started_at', 'processed_at',
])]
class Image extends Model
{
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => ImageStatus::class,
            'analysis' => 'array',
            'warnings' => 'array',
            'settings_override' => 'array',
            'apply_watermark' => 'boolean',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Image, $this> */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'duplicate_of_id');
    }

    /** @return HasMany<ImageProcessingRecord, $this> */
    public function processingRecords(): HasMany
    {
        return $this->hasMany(ImageProcessingRecord::class);
    }

    /** @param Builder<Image> $query */
    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    /** Effective settings: batch settings merged with per-image re-optimize overrides. */
    public function effectiveSettings(): array
    {
        return array_replace($this->batch?->settings ?? [], $this->settings_override ?? []);
    }
}
