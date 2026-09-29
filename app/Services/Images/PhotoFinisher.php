<?php

namespace App\Services\Images;

use App\Enums\OptimizationStrength;
use GdImage;

/**
 * "Advertisement finish" for every result, done locally (no AI, product-safe):
 *  - levels: black and white point from the photo's own histogram (clean blacks, bright whites),
 *  - a gentle S-curve for crisp contrast,
 *  - clarity/dehaze: local contrast on large structures (removes the flat, hazy look),
 *  - a little vibrance on muted colours only (saturated product colours stay as they are),
 *  - light denoise and output sharpening.
 * The same curve is used for R, G and B, so neutral greys stay neutral and
 * colours are never shifted to another hue.
 */
class PhotoFinisher
{
    /** @return array{clip: float, curve: float, clarity: float, vibrance: float, sharpen: float} */
    public function profile(OptimizationStrength $strength): array
    {
        return match ($strength) {
            OptimizationStrength::Subtle => ['clip' => 0.002, 'curve' => 0.12, 'clarity' => 0.25, 'vibrance' => 0.04, 'sharpen' => 0.22],
            OptimizationStrength::Normal => ['clip' => 0.005, 'curve' => 0.28, 'clarity' => 0.5, 'vibrance' => 0.1, 'sharpen' => 0.32],
            OptimizationStrength::Strong => ['clip' => 0.006, 'curve' => 0.3, 'clarity' => 0.6, 'vibrance' => 0.12, 'sharpen' => 0.4],
        };
    }

    public function finish(ImageEditor $editor, OptimizationStrength $strength): ImageEditor
    {
        $p = $this->profile($strength);
        $gd = $editor->gd();
        $w = imagesx($gd);
        $h = imagesy($gd);
        $lut = $this->toneCurve($gd, $p['clip'], $p['curve']);
        $base = $this->largeBlur($gd, $w, $h);
        // Transparency (PNG with removed background) is carried over unchanged.
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);

        $clarity = $p['clarity'];
        $vibrance = $p['vibrance'];

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($gd, $x, $y);
                $r = $lut[($c >> 16) & 0xFF];
                $g = $lut[($c >> 8) & 0xFF];
                $b = $lut[$c & 0xFF];

                // Clarity: push the pixel away from its wide neighbourhood, weaker
                // near black and white (no halos, no clipping), limited in size.
                $l = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $bc = imagecolorat($base, $x, $y);
                $lb = $lut[$bc & 0xFF];
                $t = $l / 255;
                $weight = 1 - (2 * $t - 1) ** 4;
                $d = max(-28, min(28, ($l - $lb) * $clarity * $weight));

                // Vibrance: muted colours a little richer, saturated ones untouched.
                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                $v = 1 + $vibrance * (1 - ($max - $min) / 255) * $weight;
                $r = $l + ($r - $l) * $v + $d;
                $g = $l + ($g - $l) * $v + $d;
                $b = $l + ($b - $l) * $v + $d;

                $px = (max(0, min(255, (int) round($r))) << 16) | (max(0, min(255, (int) round($g))) << 8) | max(0, min(255, (int) round($b)));
                imagesetpixel($out, $x, $y, $px | ($c & 0x7F000000));
            }
        }

        $editor->replaceGd($out);

        return $editor->sharpen($p['sharpen']);
    }

    /**
     * 256-entry LUT: levels (black/white point at the given clip fraction,
     * limited so a photo is never crushed or blown out) then an S-curve.
     *
     * @return list<int>
     */
    public function toneCurve(GdImage $gd, float $clip, float $curve): array
    {
        $sample = imagecreatetruecolor(160, max(1, (int) round(160 * imagesy($gd) / imagesx($gd))));
        imagecopyresampled($sample, $gd, 0, 0, 0, 0, imagesx($sample), imagesy($sample), imagesx($gd), imagesy($gd));
        $hist = array_fill(0, 256, 0);
        $n = 0;
        for ($y = 0; $y < imagesy($sample); $y++) {
            for ($x = 0; $x < imagesx($sample); $x++) {
                $c = imagecolorat($sample, $x, $y);
                // Per channel: the darkest and brightest channel values set the points.
                $hist[($c >> 16) & 0xFF]++;
                $hist[($c >> 8) & 0xFF]++;
                $hist[$c & 0xFF]++;
                $n += 3;
            }
        }

        $lo = 0;
        for ($acc = 0; $lo < 255 && ($acc += $hist[$lo]) < $n * $clip; $lo++);
        $hi = 255;
        for ($acc = 0; $hi > 0 && ($acc += $hist[$hi]) < $n * $clip; $hi--);

        // Never more than a moderate stretch: a dark night photo stays a night photo.
        $lo = min($lo, 40);
        $hi = max($hi, 200);
        $span = max(1, $hi - $lo);

        $lut = [];
        for ($i = 0; $i < 256; $i++) {
            $x = max(0.0, min(1.0, ($i - $lo) / $span));
            $s = $x * $x * (3 - 2 * $x);
            $lut[$i] = (int) round(255 * ($x + $curve * ($s - $x)));
        }

        return $lut;
    }

    /** Luma blurred over roughly 3% of the image (smooth, cheap: downscale, blur, upscale). */
    private function largeBlur(GdImage $gd, int $w, int $h): GdImage
    {
        $sw = max(8, (int) round($w / 24));
        $sh = max(8, (int) round($h / 24));
        $small = imagecreatetruecolor($sw, $sh);
        imagecopyresampled($small, $gd, 0, 0, 0, 0, $sw, $sh, $w, $h);
        imagefilter($small, IMG_FILTER_GRAYSCALE);
        for ($i = 0; $i < 3; $i++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }
        $big = imagecreatetruecolor($w, $h);
        imagecopyresampled($big, $small, 0, 0, 0, 0, $w, $h, $sw, $sh);

        return $big;
    }
}
