<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'status', 'blocked_at', 'blocked_reason', 'logo_path', 'plan', 'monthly_image_limit', 'credits_balance'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'blocked_at' => 'datetime',
            'storage_bytes' => 'integer',
            'batches_total' => 'integer',
            'images_processed_total' => 'integer',
            'images_failed_total' => 'integer',
        ];
    }

    /**
     * The single owner account of this company (v1: one user per company).
     *
     * @return HasOne<User, $this>
     */
    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->where('role', UserRole::CompanyOwner);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasOne<CompanySetting, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(CompanySetting::class);
    }

    /** @return HasMany<Batch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /** @return HasMany<Image, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(Image::class);
    }

    /** @return HasMany<ImageProcessingRecord, $this> */
    public function processingRecords(): HasMany
    {
        return $this->hasMany(ImageProcessingRecord::class);
    }

    public function isBlocked(): bool
    {
        return $this->status === CompanyStatus::Blocked;
    }

    /** Settings row, created with defaults when missing. */
    public function settingsOrDefault(): CompanySetting
    {
        return $this->settings ?? $this->settings()->create(CompanySetting::defaults());
    }
}
