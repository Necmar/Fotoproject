<?php

namespace App\Services\Processing;

use App\Models\Batch;
use App\Models\Image;
use App\Support\FileNamer;

/**
 * Names a batch the user left unnamed, from the AI analysis of its photos
 * ("BMW 320i Touring"). The first finished analysis wins; a name the user typed
 * is never replaced. File names follow (bmw-320i-touring-01.jpg) unless the
 * company chose its own file-name prefix.
 */
class BatchNamer
{
    public function fromAnalysis(Image $image, array $analysis): void
    {
        $name = $this->clean((string) data_get($analysis, 'product.short_name', ''));
        if ($name === null) {
            return;
        }

        $batch = $image->relationLoaded('batch') ? $image->batch : $image->batch()->first();
        if (! $batch || $batch->name !== null) {
            return;
        }

        $prefix = $batch->company?->settingsOrDefault()->filename_prefix;
        $attributes = ['name' => $name, 'auto_named' => true];
        if (FileNamer::base(null, $prefix) === 'foto') {
            $attributes['filename_base'] = FileNamer::base($name, null);
        }

        // Atomic: with parallel workers only the first analysis names the batch.
        Batch::query()->whereKey($batch->id)->whereNull('name')->update($attributes);

        // Files rendered from here on use the (possibly new) name.
        $image->setRelation('batch', $batch->fresh());
    }

    private function clean(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '', " \t\n\r\0\x0B.,;:-\"'");
        if (mb_strlen($name) < 2) {
            return null;
        }

        return mb_substr($name, 0, 60);
    }
}
