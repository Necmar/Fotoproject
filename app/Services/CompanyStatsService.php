<?php

namespace App\Services;

use App\Enums\BatchStatus;
use App\Enums\CompanyStatus;
use App\Enums\ImageStatus;
use App\Enums\ProcessingType;
use App\Models\Batch;
use App\Models\Company;
use App\Models\Image;
use App\Models\ImageProcessingRecord;
use Illuminate\Database\Eloquent\Builder;

/** Read-only statistics for the company dashboard and the Super Admin. */
class CompanyStatsService
{
    /**
     * Adds count/sum sub-selects to a company query for list views.
     *
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function withListStats(Builder $query): Builder
    {
        return $query
            ->withCount([
                'batches',
                'batches as active_batches_count' => fn ($q) => $q->whereIn('status', BatchStatus::activeCases()),
                'images as failed_images_count' => fn ($q) => $q->where('status', ImageStatus::Failed),
            ])
            ->withSum('processingRecords as ai_cost_usd', 'estimated_cost_usd')
            ->with(['owner:id,company_id,name,email,email_verified_at,last_login_at,locale']);
    }

    /** @return array<string, int|float|null> */
    public function forCompany(Company $company): array
    {
        $since = now()->subDays(30);

        return [
            'batches_current' => Batch::query()->forCompany($company->id)->count(),
            'batches_active' => Batch::query()->forCompany($company->id)->active()->count(),
            'batches_total' => $company->batches_total,
            // Exactly one succeeded "local" (final render) record is written per finished
            // image, so this keeps counting after the 7-day cleanup nulls image_id.
            'images_last_30_days' => ImageProcessingRecord::query()
                ->where('company_id', $company->id)
                ->where('status', ImageProcessingRecord::STATUS_SUCCEEDED)
                ->where('type', ProcessingType::Local->value)
                ->where('created_at', '>=', $since)
                ->count(),
            'images_processed_total' => $company->images_processed_total,
            'images_failed_total' => $company->images_failed_total,
            'images_failed_current' => Image::query()->forCompany($company->id)->where('status', ImageStatus::Failed)->count(),
            'storage_bytes' => $company->storage_bytes,
            'ai_requests' => ImageProcessingRecord::query()->where('company_id', $company->id)->where('provider', 'openai')->count(),
            'ai_cost_usd' => round((float) ImageProcessingRecord::query()->where('company_id', $company->id)->sum('estimated_cost_usd'), 4),
        ];
    }

    /** @return array<string, int|float> */
    public function platform(): array
    {
        return [
            'companies' => Company::query()->count(),
            'companies_blocked' => Company::query()->where('status', CompanyStatus::Blocked)->count(),
            'batches_current' => Batch::query()->count(),
            'batches_active' => Batch::query()->active()->count(),
            'images_processed_total' => (int) Company::query()->sum('images_processed_total'),
            'images_failed_current' => Image::query()->where('status', ImageStatus::Failed)->count(),
            'storage_bytes' => (int) Company::query()->sum('storage_bytes'),
            'ai_requests_30_days' => ImageProcessingRecord::query()->where('provider', 'openai')->where('created_at', '>=', now()->subDays(30))->count(),
            'ai_cost_usd_30_days' => round((float) ImageProcessingRecord::query()->where('created_at', '>=', now()->subDays(30))->sum('estimated_cost_usd'), 4),
        ];
    }
}
