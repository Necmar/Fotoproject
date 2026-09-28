<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\BatchStatus;
use App\Enums\ImageStatus;
use App\Enums\WatermarkMode;
use App\Exceptions\DomainRuleException;
use App\Models\Batch;
use App\Models\Company;
use App\Models\User;
use App\Support\BatchSettings;
use App\Support\FileNamer;
use Illuminate\Support\Facades\DB;

/**
 * Batch lifecycle up to the moment processing starts:
 * draft (photos + settings) -> queued. Processing itself is phase 4.
 */
class BatchService
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly SystemSettings $system,
    ) {}

    /** @param array<string, mixed> $settings validated settings keys (optional) */
    public function createDraft(User $owner, ?string $name = null, array $settings = []): Batch
    {
        $company = $owner->company;
        $companySettings = $company->settingsOrDefault();

        $resolved = BatchSettings::fromCompanyDefaults($companySettings, $this->hasLogo($company))->merge($settings);
        $this->assertWatermarkPossible($company, $resolved);

        $batch = Batch::query()->create([
            'company_id' => $company->id,
            'user_id' => $owner->id,
            'name' => $this->cleanName($name),
            'filename_base' => FileNamer::base($name, $companySettings->filename_prefix),
            'status' => BatchStatus::Draft,
            'settings' => $resolved->toArray(),
            'expires_at' => now()->addDays($this->system->retentionDays()),
        ]);

        $this->activity->log(ActivityAction::BatchCreated, $batch, ['name' => $batch->name], $owner, $company);

        return $batch;
    }

    /** Update name and/or settings while the batch is still a draft. */
    public function update(Batch $batch, array $data): Batch
    {
        $this->ensureDraft($batch);

        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $this->cleanName($data['name']);
            $attributes['filename_base'] = FileNamer::base($data['name'], $batch->company->settingsOrDefault()->filename_prefix);
        }

        $settingKeys = array_intersect_key($data, BatchSettings::rules());
        if ($settingKeys !== []) {
            $resolved = BatchSettings::fromArray($batch->settings)->merge($settingKeys);
            $this->assertWatermarkPossible($batch->company, $resolved);
            $attributes['settings'] = $resolved->toArray();
        }

        $batch->update($attributes);

        return $batch;
    }

    /**
     * Lock the batch and queue it. Positions are renumbered 1..n so file
     * names are gapless after photos were removed from the draft.
     */
    public function start(Batch $batch): Batch
    {
        return DB::transaction(function () use ($batch) {
            /** @var Batch $locked */
            $locked = Batch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureDraft($locked);

            $images = $locked->images()->get();

            if ($images->isEmpty()) {
                throw new DomainRuleException('batch_empty');
            }

            $this->assertWatermarkPossible($locked->company, BatchSettings::fromArray($locked->settings));

            foreach ($images->values() as $index => $image) {
                $image->update(['position' => $index + 1, 'status' => ImageStatus::Queued]);
            }

            $locked->update([
                'status' => BatchStatus::Queued,
                'images_count' => $images->count(),
                'started_at' => now(),
                'expires_at' => now()->addDays($this->system->retentionDays()),
            ]);

            Company::query()->whereKey($locked->company_id)->increment('batches_total');

            // Phase 4 dispatches one ProcessImage job per image here.

            return $locked;
        });
    }

    private function ensureDraft(Batch $batch): void
    {
        if ($batch->status !== BatchStatus::Draft && $batch->status !== BatchStatus::Uploading) {
            throw new DomainRuleException('batch_locked');
        }
    }

    private function assertWatermarkPossible(Company $company, BatchSettings $settings): void
    {
        if ($settings->watermarkMode !== WatermarkMode::None && ! $this->hasLogo($company)) {
            throw new DomainRuleException('watermark_needs_logo');
        }
    }

    private function hasLogo(Company $company): bool
    {
        return $company->logo_path !== null;
    }

    private function cleanName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : mb_substr($name, 0, 120);
    }
}
