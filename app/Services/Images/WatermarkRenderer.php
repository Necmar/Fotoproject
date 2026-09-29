<?php

namespace App\Services\Images;

use App\Enums\OutputFormat;
use App\Enums\WatermarkMode;
use App\Enums\WatermarkPosition;
use App\Models\Image;
use App\Services\Storage\LocalFiles;
use App\Services\SystemSettings;
use App\Support\BatchSettings;
use GdImage;

/**
 * Adds the company logo when a photo is downloaded. The stored result is
 * never changed, so the watermark can be switched, moved or removed at any
 * time without reprocessing.
 */
class WatermarkRenderer
{
    /** Logo width relative to the photo width. */
    private const LOGO_WIDTH = 0.18;

    private const MARGIN = 0.03;

    public function __construct(
        private readonly LocalFiles $files,
        private readonly SystemSettings $system,
    ) {}

    public function applies(Image $image, BatchSettings $settings): bool
    {
        $logo = $image->company?->logo_path;

        return $logo !== null && match ($settings->watermarkMode) {
            WatermarkMode::None => false,
            WatermarkMode::All => true,
            WatermarkMode::Selected => (bool) $image->apply_watermark,
        };
    }

    /**
     * Local temp file of the optimised photo with the logo on it.
     * The caller deletes the file after sending it.
     */
    public function render(Image $image, BatchSettings $settings): string
    {
        $photoPath = $this->files->localPath($image->optimized_path);
        $logoPath = $this->files->localPath($image->company->logo_path);

        try {
            $photo = ImageEditor::open($photoPath);
            $logo = ImageEditor::open($logoPath);

            $w = $photo->width();
            $h = $photo->height();
            $logoW = max(60, (int) round($w * self::LOGO_WIDTH));
            $logo->fitWithin($logoW, allowUpscale: true);
            // Wide logos are limited by width; tall logos by a max height.
            if ($logo->height() > $h * 0.25) {
                $logo->resize((int) round($logo->width() * ($h * 0.25) / $logo->height()), (int) round($h * 0.25));
            }

            $gd = $logo->gd();
            $this->applyOpacity($gd, $settings->watermarkOpacity);

            [$x, $y] = $this->position($settings->watermarkPosition, $w, $h, imagesx($gd), imagesy($gd), (int) round(min($w, $h) * self::MARGIN));

            $canvas = $photo->gd();
            imagealphablending($canvas, true);
            imagecopy($canvas, $gd, $x, $y, 0, 0, imagesx($gd), imagesy($gd));

            $out = $this->files->tempPath($settings->outputFormat->extension());
            $settings->outputFormat === OutputFormat::Png
                ? $photo->savePng($out)
                : $photo->saveJpeg($out, $this->system->jpgQuality());

            return $out;
        } finally {
            $this->files->release($photoPath);
            $this->files->release($logoPath);
        }
    }

    /** Multiply every pixel's alpha by the opacity (keeps logo transparency intact). */
    private function applyOpacity(GdImage $gd, int $opacity): void
    {
        $factor = max(0.1, min(1.0, $opacity / 100));
        if ($factor >= 0.999) {
            return;
        }

        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        $w = imagesx($gd);
        $h = imagesy($gd);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($gd, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F; // 0 = opaque, 127 = transparent
                $newAlpha = 127 - (int) round((127 - $alpha) * $factor);
                imagesetpixel($gd, $x, $y, imagecolorallocatealpha($gd, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF, $newAlpha));
            }
        }
    }

    /** @return array{int, int} */
    private function position(WatermarkPosition $p, int $w, int $h, int $lw, int $lh, int $m): array
    {
        return match ($p) {
            WatermarkPosition::TopLeft => [$m, $m],
            WatermarkPosition::TopRight => [$w - $lw - $m, $m],
            WatermarkPosition::BottomLeft => [$m, $h - $lh - $m],
            WatermarkPosition::BottomRight => [$w - $lw - $m, $h - $lh - $m],
            WatermarkPosition::Center => [(int) (($w - $lw) / 2), (int) (($h - $lh) / 2)],
        };
    }
}
