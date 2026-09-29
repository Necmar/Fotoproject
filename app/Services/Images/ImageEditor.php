<?php

namespace App\Services\Images;

use App\Exceptions\DomainRuleException;
use GdImage;

/**
 * Thin, chainable wrapper around GD for the non-AI image work:
 * orientation, resizing (never upscaling unless asked), smart cropping,
 * colour/contrast adjustments, sharpening and encoding.
 *
 * Re-encoding with GD writes no EXIF/XMP/GPS, so every file this class saves
 * is metadata-free.
 */
class ImageEditor
{
    private function __construct(private GdImage $gd) {}

    /** Open a JPEG or PNG and apply the EXIF orientation. */
    public static function open(string $path): self
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new DomainRuleException('corrupt_file');
        }

        MemoryGuard::ensureFor($info[0], $info[1]);

        $gd = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            default => false,
        };

        if (! $gd instanceof GdImage) {
            throw new DomainRuleException('corrupt_file');
        }

        $editor = new self($gd);

        if ($info[2] === IMAGETYPE_PNG) {
            imagealphablending($gd, false);
            imagesavealpha($gd, true);
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $editor->orient(ExifOrientation::read($path));
        }

        return $editor;
    }

    public static function fromGd(GdImage $gd): self
    {
        return new self($gd);
    }

    public function gd(): GdImage
    {
        return $this->gd;
    }

    /** Swap in a processed image of the same size. */
    public function replaceGd(GdImage $gd): self
    {
        $this->gd = $gd;

        return $this;
    }

    public function width(): int
    {
        return imagesx($this->gd);
    }

    public function height(): int
    {
        return imagesy($this->gd);
    }

    public function copy(): self
    {
        $clone = $this->blank($this->width(), $this->height());
        imagecopy($clone, $this->gd, 0, 0, 0, 0, $this->width(), $this->height());

        return new self($clone);
    }

    /** Rotate/flip according to EXIF orientation 1..8. */
    public function orient(int $orientation): self
    {
        $gd = $this->gd;

        $gd = match ($orientation) {
            2 => $this->flipped($gd, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($gd, 180, 0),
            4 => $this->flipped($gd, IMG_FLIP_VERTICAL),
            5 => $this->flipped(imagerotate($gd, -90, 0), IMG_FLIP_HORIZONTAL),
            6 => imagerotate($gd, -90, 0),
            7 => $this->flipped(imagerotate($gd, 90, 0), IMG_FLIP_HORIZONTAL),
            8 => imagerotate($gd, 90, 0),
            default => $gd,
        };

        $this->gd = $gd;

        return $this;
    }

    /** Scale so the longest side is at most $maxLongSide. Never enlarges unless $allowUpscale. */
    public function fitWithin(int $maxLongSide, bool $allowUpscale = false): self
    {
        $w = $this->width();
        $h = $this->height();
        $long = max($w, $h);

        if ($long === $maxLongSide || (! $allowUpscale && $long < $maxLongSide)) {
            return $this;
        }

        $scale = $maxLongSide / $long;

        return $this->resize(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
    }

    public function resize(int $width, int $height): self
    {
        $target = $this->blank($width, $height);
        imagecopyresampled($target, $this->gd, 0, 0, 0, 0, $width, $height, $this->width(), $this->height());
        $this->gd = $target;

        return $this;
    }

    /**
     * Crop to width/height ratio without cutting off the product.
     *
     * With a focus box (normalised x, y, w, h of the product, from the AI
     * analysis) the window always contains the whole box; if the box does not
     * fit the ratio, the canvas is extended with the border colour instead of
     * cutting into the product. Without a focus box the window is placed where
     * the image has the most detail (edge energy).
     *
     * @param  array{x: float, y: float, w: float, h: float}|null  $focus
     */
    public function cropToRatio(float $ratio, ?array $focus = null): self
    {
        $w = $this->width();
        $h = $this->height();

        if (abs($w / $h - $ratio) < 0.005) {
            return $this;
        }

        // Largest window with the target ratio inside the image.
        [$winW, $winH] = $w / $h > $ratio ? [(int) round($h * $ratio), $h] : [$w, (int) round($w / $ratio)];

        if ($focus !== null) {
            $fx = (int) floor($focus['x'] * $w);
            $fy = (int) floor($focus['y'] * $h);
            $fw = (int) ceil($focus['w'] * $w);
            $fh = (int) ceil($focus['h'] * $h);

            if ($fw > $winW || $fh > $winH) {
                return $this->extendAround($ratio, $fx, $fy, $fw, $fh);
            }

            $x = $this->clamp((int) round($fx + $fw / 2 - $winW / 2), 0, $w - $winW);
            $y = $this->clamp((int) round($fy + $fh / 2 - $winH / 2), 0, $h - $winH);
        } else {
            [$x, $y] = $this->bestWindow($winW, $winH);
        }

        return $this->crop($x, $y, $winW, $winH);
    }

    public function crop(int $x, int $y, int $width, int $height): self
    {
        $target = $this->blank($width, $height);
        imagecopy($target, $this->gd, 0, 0, $x, $y, $width, $height);
        $this->gd = $target;

        return $this;
    }

    /** Put transparent areas on a solid background (JPEG has no alpha). */
    public function flatten(array $rgb = [255, 255, 255]): self
    {
        $w = $this->width();
        $h = $this->height();
        $target = imagecreatetruecolor($w, $h);
        imagefill($target, 0, 0, imagecolorallocate($target, ...$rgb));
        imagealphablending($target, true);
        imagecopy($target, $this->gd, 0, 0, 0, 0, $w, $h);
        $this->gd = $target;

        return $this;
    }

    /**
     * Global corrections computed by LocalEnhancer.
     *
     * @param  array{gamma?: float, contrast?: int, brightness?: int, red?: int, green?: int, blue?: int, sharpen?: float}  $a
     */
    public function adjust(array $a): self
    {
        imagealphablending($this->gd, false);

        if (($a['gamma'] ?? 1.0) !== 1.0) {
            imagegammacorrect($this->gd, $a['gamma'], 1.0);
        }

        if (($a['brightness'] ?? 0) !== 0) {
            imagefilter($this->gd, IMG_FILTER_BRIGHTNESS, $a['brightness']);
        }

        if (($a['contrast'] ?? 0) !== 0) {
            imagefilter($this->gd, IMG_FILTER_CONTRAST, $a['contrast']);
        }

        if (($a['red'] ?? 0) !== 0 || ($a['green'] ?? 0) !== 0 || ($a['blue'] ?? 0) !== 0) {
            imagefilter($this->gd, IMG_FILTER_COLORIZE, $a['red'] ?? 0, $a['green'] ?? 0, $a['blue'] ?? 0, 0);
        }

        if (($a['denoise'] ?? 0.0) > 0.05) {
            // Mild smoothing; lower weight = stronger. Kept gentle to preserve detail.
            imagefilter($this->gd, IMG_FILTER_SMOOTH, (int) round(40 - 25 * min(1, (float) $a['denoise'])));
        }

        if (($a['sharpen'] ?? 0.0) > 0) {
            $this->sharpen((float) $a['sharpen']);
        }

        return $this;
    }

    /**
     * Straighten a slightly crooked photo and crop away the empty corners.
     * Only small angles (max 5 degrees) are allowed.
     */
    public function straighten(float $degrees): self
    {
        $degrees = max(-5.0, min(5.0, $degrees));
        if (abs($degrees) < 0.3) {
            return $this;
        }

        $w = $this->width();
        $h = $this->height();
        $rotated = imagerotate($this->gd, -$degrees, imagecolorallocatealpha($this->gd, 0, 0, 0, 127));

        // Largest axis-aligned rectangle with the original ratio inside the rotated image.
        $a = deg2rad(abs($degrees));
        $scale = 1 / (cos($a) + max($w / $h, $h / $w) * sin($a));
        $cw = (int) floor($w * $scale);
        $ch = (int) floor($h * $scale);

        $this->gd = $rotated;

        return $this->crop((int) floor((imagesx($rotated) - $cw) / 2), (int) floor((imagesy($rotated) - $ch) / 2), $cw, $ch);
    }

    /** Light unsharp-style convolution; amount 0.1 (subtle) to 0.5 (strong). */
    public function sharpen(float $amount): self
    {
        $s = -$amount;
        $c = 1 + 4 * $amount;
        imageconvolution($this->gd, [[0, $s, 0], [$s, $c, $s], [0, $s, 0]], 1, 0);

        return $this;
    }

    public function saveJpeg(string $path, int $quality): void
    {
        $gd = $this->hasAlpha() ? $this->copy()->flatten()->gd() : $this->gd;
        imageinterlace($gd, true); // progressive JPEG loads nicer on phones
        $this->write(fn () => imagejpeg($gd, $path, $quality), $path);
    }

    public function savePng(string $path): void
    {
        imagesavealpha($this->gd, true);
        $this->write(fn () => imagepng($this->gd, $path, 6), $path);
    }

    /**
     * Small grayscale sample as a 2D array of 0..255 values (for analysis).
     *
     * @return list<list<int>>
     */
    public function grayscaleSample(int $maxLongSide): array
    {
        $sample = $this->copy()->fitWithin($maxLongSide)->gd();
        $w = imagesx($sample);
        $h = imagesy($sample);
        $rows = [];

        for ($y = 0; $y < $h; $y++) {
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($sample, $x, $y);
                $row[] = (int) ((($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Average colour of the outer border (used to extend the canvas).
     *
     * @return array{int, int, int}
     */
    public function borderColor(): array
    {
        $sample = $this->copy()->fitWithin(64)->gd();
        $w = imagesx($sample);
        $h = imagesy($sample);
        $sum = [0, 0, 0];
        $n = 0;

        for ($x = 0; $x < $w; $x++) {
            foreach ([0, $h - 1] as $y) {
                $this->accumulate($sample, $x, $y, $sum, $n);
            }
        }
        for ($y = 0; $y < $h; $y++) {
            foreach ([0, $w - 1] as $x) {
                $this->accumulate($sample, $x, $y, $sum, $n);
            }
        }

        return array_map(fn ($v) => (int) round($v / max(1, $n)), $sum);
    }

    private function accumulate(GdImage $img, int $x, int $y, array &$sum, int &$n): void
    {
        $rgb = imagecolorat($img, $x, $y);
        $sum[0] += ($rgb >> 16) & 0xFF;
        $sum[1] += ($rgb >> 8) & 0xFF;
        $sum[2] += $rgb & 0xFF;
        $n++;
    }

    /** Canvas of the target ratio that contains the focus box, extended with the border colour. */
    private function extendAround(float $ratio, int $fx, int $fy, int $fw, int $fh): self
    {
        $w = $this->width();
        $h = $this->height();
        $margin = 1.04; // a little breathing room around the product

        $cw = (int) ceil(max($fw * $margin, $fh * $margin * $ratio));
        $ch = (int) ceil($cw / $ratio);

        // Prefer showing as much real image as possible: centre on the focus box.
        $sx = (int) round($fx + $fw / 2 - $cw / 2);
        $sy = (int) round($fy + $fh / 2 - $ch / 2);

        $fill = $this->borderColor();
        $canvas = imagecreatetruecolor($cw, $ch);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...$fill));

        $srcX = max(0, $sx);
        $srcY = max(0, $sy);
        $copyW = min($w, $sx + $cw) - $srcX;
        $copyH = min($h, $sy + $ch) - $srcY;
        imagecopy($canvas, $this->gd, $srcX - $sx, $srcY - $sy, $srcX, $srcY, $copyW, $copyH);

        $this->gd = $canvas;

        return $this;
    }

    /** Position of the crop window with the most edge energy (detail) inside. */
    private function bestWindow(int $winW, int $winH): array
    {
        $w = $this->width();
        $h = $this->height();
        $gray = $this->grayscaleSample(160);
        $sh = count($gray);
        $sw = count($gray[0]);
        $scale = $w / $sw;

        $cols = array_fill(0, $sw, 0);
        $rows = array_fill(0, $sh, 0);
        for ($y = 1; $y < $sh - 1; $y++) {
            for ($x = 1; $x < $sw - 1; $x++) {
                $e = abs($gray[$y][$x + 1] - $gray[$y][$x - 1]) + abs($gray[$y + 1][$x] - $gray[$y - 1][$x]);
                $cols[$x] += $e;
                $rows[$y] += $e;
            }
        }

        if ($winW < $w) {
            $x = $this->slide($cols, (int) round($winW / $scale));

            return [$this->clamp((int) round($x * $scale), 0, $w - $winW), 0];
        }

        $y = $this->slide($rows, (int) round($winH / $scale));

        return [0, $this->clamp((int) round($y * $scale), 0, $h - $winH)];
    }

    /** Start index of the window of $size with the highest sum; ties prefer the centre. */
    private function slide(array $values, int $size): int
    {
        $n = count($values);
        $size = max(1, min($size, $n));
        $sum = array_sum(array_slice($values, 0, $size));
        $best = $sum;
        $bestStart = 0;
        $centre = ($n - $size) / 2;

        for ($start = 1; $start <= $n - $size; $start++) {
            $sum += $values[$start + $size - 1] - $values[$start - 1];
            if ($sum > $best * 1.02 || (abs($sum - $best) <= $best * 0.02 && abs($start - $centre) < abs($bestStart - $centre))) {
                $best = max($best, $sum);
                $bestStart = $start;
            }
        }

        return $bestStart;
    }

    private function hasAlpha(): bool
    {
        return ! imageistruecolor($this->gd) || $this->probeAlpha();
    }

    private function probeAlpha(): bool
    {
        $w = $this->width();
        $h = $this->height();
        foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1], [(int) ($w / 2), (int) ($h / 2)]] as [$x, $y]) {
            if (((imagecolorat($this->gd, $x, $y) >> 24) & 0x7F) > 0) {
                return true;
            }
        }

        return false;
    }

    private function blank(int $w, int $h): GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));

        return $img;
    }

    private function flipped(GdImage $gd, int $mode): GdImage
    {
        imageflip($gd, $mode);

        return $gd;
    }

    private function clamp(int $v, int $min, int $max): int
    {
        return max($min, min($max, $v));
    }

    private function write(callable $writer, string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (! $writer()) {
            throw new \RuntimeException('Could not write image to '.basename($path));
        }
    }
}
