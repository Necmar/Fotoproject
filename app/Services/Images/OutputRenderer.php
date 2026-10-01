<?php

namespace App\Services\Images;

use App\Enums\AspectRatio;
use App\Enums\OptimizationStrength;
use App\Enums\OutputFormat;
use App\Models\Image;
use App\Services\Storage\LocalFiles;
use App\Services\Storage\StorageAccounting;
use App\Services\SystemSettings;
use App\Support\BatchSettings;
use App\Support\FileNamer;
use Illuminate\Support\Str;

/**
 * Final, non-AI step: crop to the chosen aspect ratio (keeping the product
 * in view), resize to the chosen resolution without upscaling, optionally
 * apply local corrections, and encode as JPG (85 to 90 %) or PNG. The result
 * never contains EXIF/GPS metadata. A new render replaces the previous one.
 *
 * The watermark is NOT applied here: it is added only when downloading (phase 7),
 * so the stored result stays clean and settings can still change.
 */
class OutputRenderer
{
    public function __construct(
        private readonly LocalFiles $files,
        private readonly StorageAccounting $storage,
        private readonly SystemSettings $system,
        private readonly PhotoFinisher $finisher,
    ) {}

    /**
     * @param  string|null  $sourcePath  disk path of the image to render (default: working copy; phase 5: AI result)
     * @param  array|null  $adjustments  output of LocalEnhancer::adjustments(), or null for none
     * @param  array{x: float, y: float, w: float, h: float}|null  $focus  product box (normalised) for smart cropping
     * @param  OptimizationStrength|null  $finish  local finish to apply (null: none, e.g. an AI-retouched source)
     */
    public function render(Image $image, BatchSettings $settings, ?string $sourcePath = null, ?array $adjustments = null, ?array $focus = null, ?OptimizationStrength $finish = null): Image
    {
        $image->loadMissing('batch');
        $sourcePath ??= $image->working_path;
        $local = $this->files->localPath($sourcePath);

        try {
            $editor = ImageEditor::open($local);
            $before = max($editor->width(), $editor->height());

            if (($adjustments['rotate'] ?? 0) != 0) {
                $editor->straighten((float) $adjustments['rotate']);
            }

            if ($settings->aspectRatio !== AspectRatio::Original) {
                $editor->cropToRatio($settings->aspectRatio->ratio(), $focus);
            }

            $long = max($editor->width(), $editor->height());
            $target = $this->targetSide($image, $settings, $sourcePath, $before, $long);
            // Only an AI result may be enlarged, and targetSide() caps that at the photo's own size.
            $editor->fitWithin($target, $sourcePath !== $image->working_path && $target > $long);

            if ($adjustments) {
                // The finish below sharpens once, at output size.
                $editor->adjust($finish ? ['sharpen' => 0.0] + $adjustments : $adjustments);
            }

            // Clean advertisement look (levels, curve, clarity, vibrance, sharpening).
            if ($finish) {
                $this->finisher->finish($editor, $finish);
            }

            $extension = $settings->outputFormat->extension();
            $target = $image->batch->storageDirectory().'/optimized/'.Str::ulid()->toBase32().'.'.$extension;
            $out = $this->files->writablePath($target);

            $settings->outputFormat === OutputFormat::Png
                ? $editor->savePng($out)
                : $editor->saveJpeg($out, $this->system->jpgQuality());

            $bytes = $this->files->commit($out, $target);
            $previous = $image->optimized_path;
            $previousBytes = (int) ($image->output_size ?? 0);

            // Small preview for the photo grid: loads fast, also on phones.
            $png = $settings->outputFormat === OutputFormat::Png;
            $previewPath = $image->batch->storageDirectory().'/thumbs/'.Str::ulid()->toBase32().($png ? '.png' : '.jpg');
            $preview = $editor->copy()->fitWithin((int) config('bora.processing.preview_side', 720));
            $previewOut = $this->files->writablePath($previewPath);
            $png ? $preview->savePng($previewOut) : $preview->saveJpeg($previewOut, 82);
            $previewBytes = $this->files->commit($previewOut, $previewPath);
            $previousPreview = $image->preview_path;
            $previousPreviewBytes = $previousPreview && $this->files->disk()->exists($previousPreview) ? (int) $this->files->disk()->size($previousPreview) : 0;

            $image->forceFill([
                'optimized_path' => $target,
                'preview_path' => $previewPath,
                'output_filename' => FileNamer::numbered($image->batch->filename_base, $image->position, $extension),
                'output_size' => $bytes,
                'output_width' => $editor->width(),
                'output_height' => $editor->height(),
            ])->save();

            if ($previous && $previous !== $target) {
                $this->files->disk()->delete($previous);
            }

            if ($previousPreview && $previousPreview !== $previewPath) {
                $this->files->disk()->delete($previousPreview);
            }

            $this->storage->add($image->batch, $bytes - $previousBytes + $previewBytes - $previousPreviewBytes);

            return $image;
        } finally {
            $this->files->release($local);
        }
    }

    /**
     * Long side of the result. Normally the chosen resolution, never upscaled.
     * An AI result is often smaller than the photo itself (OPENAI_MAX_SIDE, e.g.
     * 1024 px). Then it is enlarged to the chosen resolution, but never beyond
     * what the original photo had: the resolution setting keeps working without
     * inventing pixels the camera never took.
     */
    private function targetSide(Image $image, BatchSettings $settings, string $sourcePath, int $sourceLong, int $croppedLong): int
    {
        $wanted = $settings->resolution->pixels();
        if ($sourcePath === $image->working_path || ! $image->working_path || $sourceLong <= 0) {
            return $wanted;
        }

        $size = @getimagesize($this->files->localPath($image->working_path));
        $originalLong = $size ? max((int) $size[0], (int) $size[1]) : 0;
        if ($originalLong <= $sourceLong) {
            return min($wanted, $croppedLong); // the AI result is not smaller than the photo: no enlarging
        }

        // Same crop, at the original's scale: the long side the photo itself could give.
        return min($wanted, (int) floor($croppedLong * $originalLong / $sourceLong));
    }
}
