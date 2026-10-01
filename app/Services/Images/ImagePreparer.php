<?php

namespace App\Services\Images;

use App\Exceptions\DomainRuleException;
use App\Models\Image;
use App\Services\OpenAI\OpenAIClient;
use App\Services\Storage\LocalFiles;
use App\Services\SystemSettings;
use App\Services\Storage\StorageAccounting;
use Illuminate\Support\Str;

/**
 * Turns an uploaded original into what the rest of the pipeline needs:
 *  - HEIC/HEIF converted to JPEG (server fallback),
 *  - EXIF orientation applied,
 *  - a metadata-free working copy (max 3072 px),
 *  - a small thumbnail for the UI,
 *  - local quality metrics + warnings and a perceptual hash.
 *
 * Runs right after upload so broken files and failed conversions are
 * reported immediately. Idempotent: running it again replaces the files.
 */
class ImagePreparer
{
    public function __construct(
        private readonly ImageTypeDetector $detector,
        private readonly HeicConverter $heic,
        private readonly LocalEnhancer $enhancer,
        private readonly LocalFiles $files,
        private readonly StorageAccounting $storage,
        private readonly OpenAIClient $openai,
        private readonly SystemSettings $system,
    ) {}

    public function prepare(Image $image): Image
    {
        $image->loadMissing('batch');
        // The original is removed once a working copy exists; without either there is nothing to work from.
        if (! $image->original_path || ! $this->files->disk()->exists($image->original_path)) {
            throw new \App\Exceptions\DomainRuleException('file_missing', [], 404);
        }
        $original = $this->files->localPath($image->original_path);
        $converted = null;

        try {
            $source = $original;

            if ($this->detector->isHeif($image->original_mime)) {
                $converted = $this->files->tempPath('jpg');

                if (! $this->heic->convert($original, $converted)) {
                    throw new DomainRuleException('conversion_failed');
                }

                $source = $converted;
            }

            $editor = ImageEditor::open($source);

            if ($editor->width() * $editor->height() > ImageUploadService::MAX_PIXELS) {
                throw new DomainRuleException('too_many_pixels');
            }

            $width = $editor->width();
            $height = $editor->height();

            $dir = $image->batch->storageDirectory();
            $workingPath = "{$dir}/working/".Str::ulid()->toBase32().'.jpg';
            $thumbPath = "{$dir}/thumbs/".Str::ulid()->toBase32().'.jpg';

            $working = $editor->fitWithin((int) config('bora.processing.working_max_side'));
            $local = $this->files->writablePath($workingPath);
            $working->saveJpeg($local, (int) config('bora.processing.working_quality'));
            $workingBytes = $this->files->commit($local, $workingPath);

            $metrics = $this->enhancer->analyze($working);
            $hash = PerceptualHash::fromEditor($working);

            $thumb = $working->copy()->fitWithin((int) config('bora.processing.thumbnail_side'));
            $local = $this->files->writablePath($thumbPath);
            $thumb->saveJpeg($local, (int) config('bora.processing.thumbnail_quality'));
            $thumbBytes = $this->files->commit($local, $thumbPath);

            $previous = array_filter([$image->working_path, $image->thumbnail_path]);
            $previousBytes = $this->sizes($previous);

            $image->forceFill([
                'width' => $width,
                'height' => $height,
                'working_path' => $workingPath,
                'thumbnail_path' => $thumbPath,
                'perceptual_hash' => $hash,
                'analysis' => array_replace($image->analysis ?? [], ['local' => $metrics]),
                // With AI the analysis judges the photo shortly; the simple local
                // check would only give false alarms (e.g. a white background) until then.
                'warnings' => $this->mergeWarnings($image->warnings ?? [], 'local', $this->aiWillJudge() ? [] : $this->enhancer->warnings($metrics)),
            ])->save();

            if ($previous) {
                $this->files->disk()->delete($previous);
            }

            $this->storage->add($image->batch, $workingBytes + $thumbBytes - $previousBytes);

            return $image;
        } finally {
            $this->files->release($original);
            if ($converted) {
                @unlink($converted);
            }
        }
    }

    /** Same condition as AiImageProcessor::isActive (not injected: that class depends on this one). */
    private function aiWillJudge(): bool
    {
        return $this->openai->isConfigured() && (bool) $this->system->get('ai_enabled', true);
    }

    /**
     * Replace warnings from one source ('local', 'ai', 'duplicate') and keep the others.
     *
     * @param  list<array{code: string, source?: string, params?: array}>  $existing
     * @param  list<array{code: string, params?: array}>  $new
     */
    public function mergeWarnings(array $existing, string $source, array $new): array
    {
        $kept = array_values(array_filter($existing, fn ($w) => ($w['source'] ?? null) !== $source));

        return array_merge($kept, array_map(fn ($w) => $w + ['source' => $source], $new));
    }

    private function sizes(array $paths): int
    {
        $disk = $this->files->disk();

        return array_sum(array_map(fn ($p) => $disk->exists($p) ? $disk->size($p) : 0, $paths));
    }
}
