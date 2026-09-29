<?php

namespace App\Models;

use App\Enums\BatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'company_id', 'user_id', 'name', 'filename_base', 'status', 'settings',
    'images_count', 'completed_count', 'failed_count', 'storage_bytes',
    'zip_path', 'zip_generated_at', 'started_at', 'completed_at', 'notified_at', 'expires_at',
])]
class Batch extends Model
{
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'settings' => 'array',
            'zip_generated_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'notified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Image, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(Image::class)->orderBy('position');
    }

    /**
     * First photo of the batch, for list thumbnails.
     *
     * @return HasOne<Image, $this>
     */
    public function cover(): HasOne
    {
        return $this->hasOne(Image::class)->ofMany('position', 'min');
    }

    /** @param Builder<Batch> $query */
    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    /** @param Builder<Batch> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', BatchStatus::activeCases());
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /** Directory (relative to the private disk) holding every file of this batch. */
    public function storageDirectory(): string
    {
        return "companies/{$this->company_id}/batches/{$this->id}";
    }
}
