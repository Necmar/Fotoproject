<?php

namespace App\Services\Images;

use App\Enums\BatchStatus;
use App\Enums\ImageStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Batch;
use App\Models\Image;
use App\Services\Storage\StorageAccounting;
use App\Services\SystemSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Validates and stores one uploaded original. One file per request keeps
 * every request well below shared-hosting POST and execution limits.
 */
class ImageUploadService
{
    /** Refuse images that would not fit in PHP memory during processing. */
    public const MAX_PIXELS = 60_000_000;

    public function __construct(
        private readonly ImageTypeDetector $detector,
        private readonly SystemSettings $settings,
        private readonly StorageAccounting $storage,
    ) {}

    public function store(Batch $batch, UploadedFile $file): Image
    {
        $this->ensureAcceptsUploads($batch);

        if (! $file->isValid()) {
            throw new DomainRuleException('upload_failed');
        }

        $maxBytes = $this->settings->maxUploadMb() * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new DomainRuleException('file_too_large', ['max' => $this->settings->maxUploadMb()]);
        }

        $mime = $this->detector->detect($file->getRealPath());
        if ($mime === null) {
            throw new DomainRuleException('invalid_type');
        }

        [$width, $height] = $this->dimensions($file->getRealPath(), $mime);

        if ($width !== null && $width * $height > self::MAX_PIXELS) {
            throw new DomainRuleException('too_many_pixels');
        }

        $path = $batch->storageDirectory().'/original/'.Str::ulid()->toBase32().'.'.ImageTypeDetector::extensionFor($mime);
        $disk = Storage::disk(config('bora.disk'));

        if ($disk->putFileAs(dirname($path), $file, basename($path)) === false) {
            throw new DomainRuleException('upload_failed');
        }

        try {
            $image = DB::transaction(function () use ($batch, $file, $mime, $width, $height, $path) {
                /** @var Batch $locked */
                $locked = Batch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
                $this->ensureAcceptsUploads($locked);

                $max = $this->settings->maxImagesPerBatch();
                $count = $locked->images()->count();

                if ($count >= $max) {
                    throw new DomainRuleException('too_many_images', ['max' => $max]);
                }

                $image = Image::query()->create([
                    'batch_id' => $locked->id,
                    'company_id' => $locked->company_id,
                    'position' => ((int) $locked->images()->max('position')) + 1,
                    'original_filename' => $this->cleanClientName($file->getClientOriginalName()),
                    'original_path' => $path,
                    'original_mime' => $mime,
                    'original_size' => $file->getSize(),
                    'width' => $width,
                    'height' => $height,
                    'content_hash' => hash_file('sha256', $file->getRealPath()),
                    'status' => ImageStatus::Uploaded,
                ]);

                $locked->update(['images_count' => $count + 1]);

                return $image;
            });
        } catch (Throwable $e) {
            $disk->delete($path);
            throw $e;
        }

        $this->storage->add($batch, (int) $file->getSize());

        return $image;
    }

    /** Remove one image from a batch that has not been started yet. */
    public function delete(Batch $batch, Image $image): void
    {
        $this->ensureAcceptsUploads($batch);

        $bytes = (int) $image->original_size;
        $paths = array_filter([$image->original_path, $image->working_path, $image->thumbnail_path, $image->optimized_path]);

        DB::transaction(function () use ($batch, $image) {
            $image->delete();
            $batch->update(['images_count' => $batch->images()->count()]);
        });

        Storage::disk(config('bora.disk'))->delete($paths);
        $this->storage->add($batch, -$bytes);
    }

    private function ensureAcceptsUploads(Batch $batch): void
    {
        if (! in_array($batch->status, [BatchStatus::Draft, BatchStatus::Uploading], true)) {
            throw new DomainRuleException('batch_locked');
        }
    }

    /**
     * @return array{0: ?int, 1: ?int}
     *
     * @throws DomainRuleException when a JPEG/PNG cannot be read (corrupt file)
     */
    private function dimensions(string $path, string $mime): array
    {
        if ($this->detector->isHeif($mime)) {
            // PHP cannot read HEIC headers without Imagick; dimensions are filled
            // in after conversion (phase 3). The browser converts HEIC first when it can.
            return [null, null];
        }

        $info = @getimagesize($path);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new DomainRuleException('corrupt_file');
        }

        return [$info[0], $info[1]];
    }

    private function cleanClientName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $name) ?? '');

        return Str::limit($name !== '' ? $name : 'foto', 200, '');
    }
}
