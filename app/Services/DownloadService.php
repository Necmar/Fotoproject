<?php

namespace App\Services;

use App\Enums\ImageStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Batch;
use App\Models\Image;
use App\Services\Images\WatermarkRenderer;
use App\Services\Storage\LocalFiles;
use App\Services\Storage\StorageAccounting;
use App\Support\BatchSettings;
use ZipArchive;

/**
 * Single-photo and ZIP downloads. Watermarks are added here, at the very
 * end. A ZIP is only built when someone asks for it and is reused until the
 * photos or watermark settings change.
 */
class DownloadService
{
    public function __construct(
        private readonly WatermarkRenderer $watermark,
        private readonly LocalFiles $files,
        private readonly StorageAccounting $storage,
    ) {}

    /** @return array{path: string, name: string, temporary: bool} */
    public function single(Image $image): array
    {
        if ($image->status !== ImageStatus::Completed || ! $image->optimized_path) {
            throw new DomainRuleException('nothing_to_download');
        }

        $image->loadMissing(['batch', 'company']);
        $settings = BatchSettings::fromArray($image->batch->settings);

        if ($this->watermark->applies($image, $settings)) {
            return ['path' => $this->watermark->render($image, $settings), 'name' => $image->output_filename, 'temporary' => true];
        }

        return ['path' => $this->files->localPath($image->optimized_path), 'name' => $image->output_filename, 'temporary' => false];
    }

    /** @return array{path: string, name: string} */
    public function zip(Batch $batch): array
    {
        $batch->loadMissing('company');
        $images = $batch->images()->where('status', ImageStatus::Completed)->whereNotNull('optimized_path')->get();

        if ($images->isEmpty()) {
            throw new DomainRuleException('nothing_to_download');
        }

        $images->each(fn (Image $i) => $i->setRelation('batch', $batch)->setRelation('company', $batch->company));
        $settings = BatchSettings::fromArray($batch->settings);
        $signature = $this->signature($batch, $images, $settings);
        $diskPath = $batch->storageDirectory()."/zip/{$signature}.zip";
        $disk = $this->files->disk();

        if (! $disk->exists($diskPath)) {
            $this->build($batch, $images, $settings, $diskPath);
        }

        return ['path' => $this->files->localPath($diskPath), 'name' => $batch->filename_base.'.zip'];
    }

    private function build(Batch $batch, $images, BatchSettings $settings, string $diskPath): void
    {
        $local = $this->files->writablePath($diskPath);
        $zip = new ZipArchive;

        if ($zip->open($local, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create ZIP');
        }

        $temps = [];

        try {
            foreach ($images as $image) {
                $file = $this->single($image);
                $zip->addFile($file['path'], $file['name']);
                // JPEG/PNG are already compressed: store instead of deflate (much faster).
                $zip->setCompressionName($file['name'], ZipArchive::CM_STORE);
                $file['temporary'] ? $temps[] = $file['path'] : null;
            }

            $zip->close();
        } finally {
            array_map('unlink', array_filter($temps, 'is_file'));
        }

        // Only the newest ZIP is kept.
        foreach ($this->files->disk()->files($batch->storageDirectory().'/zip') as $old) {
            if ($old !== $diskPath) {
                $this->storage->add($batch, -(int) $this->files->disk()->size($old));
                $this->files->disk()->delete($old);
            }
        }

        $bytes = $this->files->commit($local, $diskPath);
        $this->storage->add($batch, $bytes);
        $batch->forceFill(['zip_path' => $diskPath, 'zip_generated_at' => now()])->save();
    }

    /** Changes whenever a photo result, the selection or the watermark changes. */
    private function signature(Batch $batch, $images, BatchSettings $settings): string
    {
        $parts = [$batch->filename_base, $batch->company->logo_path, $settings->watermarkMode->value, $settings->watermarkPosition->value, $settings->watermarkOpacity];

        foreach ($images as $image) {
            $parts[] = $image->id.':'.$image->optimized_path.':'.(int) $image->apply_watermark;
        }

        return substr(sha1(implode('|', $parts)), 0, 20);
    }
}
