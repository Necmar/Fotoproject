<?php

namespace App\Services\Images;

use App\Enums\AspectRatio;
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
    ) {}

    /**
     * @param  string|null  $sourcePath  disk path of the image to render (default: working copy; phase 5: AI result)
     * @param  array|null  $adjustments  output of LocalEnhancer::adjustments(), or null for none
     * @param  array{x: float, y: float, w: float, h: float}|null  $focus  product box (normalised) for smart cropping
     */
    public function render(Image $image, BatchSettings $settings, ?string $sourcePath = null, ?array $adjustments = null, ?array $focus = null): Image
    {
        $image->loadMissing('batch');
        $sourcePath ??= $image->working_path;
        $local = $this->files->localPath($sourcePath);

        try {
            $editor = ImageEditor::open($local);

            if ($settings->aspectRatio !== AspectRatio::Original) {
                $editor->cropToRatio($settings->aspectRatio->ratio(), $focus);
            }

            $editor->fitWithin($settings->resolution->pixels());

            if ($adjustments) {
                $editor->adjust($adjustments);
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

            $image->forceFill([
                'optimized_path' => $target,
                'output_filename' => FileNamer::numbered($image->batch->filename_base, $image->position, $extension),
                'output_size' => $bytes,
                'output_width' => $editor->width(),
                'output_height' => $editor->height(),
            ])->save();

            if ($previous && $previous !== $target) {
                $this->files->disk()->delete($previous);
            }

            $this->storage->add($image->batch, $bytes - $previousBytes);

            return $image;
        } finally {
            $this->files->release($local);
        }
    }
}
