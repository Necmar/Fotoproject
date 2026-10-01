<?php

namespace App\Services\Images;

use GdImage;
use RuntimeException;

/**
 * Product-preserving background work. The AI only delivers a cut-out of the
 * product on a flat key colour; from that we derive a mask and put the
 * ORIGINAL product pixels (with global light/colour corrections only) on the
 * chosen background. The product can therefore never be redrawn, recoloured
 * or "repaired" by the image model.
 */
class ProductCompositor
{
    /** Candidate key colours for the cut-out; the one least present in the photo is used. */
    public const KEY_COLOURS = [
        'magenta' => [255, 0, 255],
        'green' => [0, 255, 0],
        'blue' => [0, 0, 255],
    ];

    /** @return array{0: string, 1: array{int, int, int}} name and RGB of the best key colour for this photo */
    public function keyColourFor(ImageEditor $photo): array
    {
        $sample = $photo->copy()->fitWithin(96)->gd();
        $w = imagesx($sample);
        $h = imagesy($sample);
        $best = null;

        foreach (self::KEY_COLOURS as $name => $rgb) {
            $near = 0;
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $c = imagecolorat($sample, $x, $y);
                    $near += $this->distance([($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF], $rgb) < 0.45 ? 1 : 0;
                }
            }
            if ($best === null || $near < $best[2]) {
                $best = [$name, $rgb, $near];
            }
        }

        return [$best[0], $best[1]];
    }

    /**
     * Mask (0 = background, 255 = product) at $width x $height from the AI cut-out.
     *
     * @return array{mask: GdImage, coverage: float}
     */
    public function maskFromCutout(ImageEditor $cutout, array $key, int $width, int $height, ?array $transform = null): array
    {
        $gd = $this->placed($cutout, $key, $width, $height, $transform);
        $mask = imagecreatetruecolor($width, $height);
        $sum = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $c = imagecolorat($gd, $x, $y);
                $d = $this->distance([($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF], $key);
                // Soft edge between "clearly key colour" and "clearly something else".
                $a = (int) round(max(0, min(1, ($d - 0.22) / 0.2)) * 255);
                $sum += $a;
                imagesetpixel($mask, $x, $y, $a | ($a << 8) | ($a << 16));
            }
        }

        return ['mask' => $this->soften($mask), 'coverage' => $sum / (255 * $width * $height)];
    }

    /**
     * The real background colour of the cut-out (models rarely hit the exact
     * key colour): median of the outer border, if it is close to the requested key.
     *
     * @return array{int, int, int}
     */
    public function measuredKey(ImageEditor $cutout, array $requested): array
    {
        $gd = $cutout->copy()->fitWithin(200)->gd();
        $w = imagesx($gd);
        $h = imagesy($gd);
        $r = $g = $b = [];
        $band = max(2, (int) round(min($w, $h) * 0.03));

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($x >= $band && $x < $w - $band && $y >= $band && $y < $h - $band) {
                    continue;
                }
                $c = imagecolorat($gd, $x, $y);
                $px = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
                if ($this->distance($px, $requested) < 0.35) {
                    $r[] = $px[0];
                    $g[] = $px[1];
                    $b[] = $px[2];
                }
            }
        }

        if (count($r) < 20) {
            return $requested;
        }
        sort($r);
        sort($g);
        sort($b);
        $mid = intdiv(count($r), 2);

        return [$r[$mid], $g[$mid], $b[$mid]];
    }

    /**
     * Where did the model put the product? Image models often shift or scale
     * the frame a little. Searches scale and offset (coarse to fine, on small
     * copies) for the placement where the cut-out product best matches the
     * original, and returns it with the remaining mean difference (0..1).
     *
     * @return array{scale: float, dx: float, dy: float, error: float}
     */
    public function register(ImageEditor $original, ImageEditor $cutout, array $key): array
    {
        $o = $original->copy()->fitWithin(112)->gd();
        $w = imagesx($o);
        $h = imagesy($o);
        $luma = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $luma[$y][$x] = $this->luma(imagecolorat($o, $x, $y));
            }
        }

        // Placement is relative (scale around the centre, shift as a fraction of the
        // frame), so it is searched on a small copy of the cut-out: resampling the
        // full-size cut-out ~300 times took seconds per photo. Twice the target size
        // keeps the resampled edges the same as before.
        $cutout = $cutout->copy()->fitWithin(2 * max($w, $h));

        $best = ['scale' => 1.0, 'dx' => 0.0, 'dy' => 0.0, 'error' => $this->placementError($luma, $cutout, $key, $w, $h, null)];
        $rounds = [[[0.94, 0.97, 1.0, 1.03, 1.06], [-0.06, -0.04, -0.02, 0.0, 0.02, 0.04, 0.06]], [[-0.015, 0.0, 0.015], [-0.01, 0.0, 0.01]]];

        foreach ($rounds as $round => [$scales, $shifts]) {
            $centre = $best;
            foreach ($scales as $s) {
                $scale = $round === 0 ? $s : $centre['scale'] + $s;
                foreach ($shifts as $sx) {
                    foreach ($shifts as $sy) {
                        $t = ['scale' => $scale, 'dx' => ($round === 0 ? 0 : $centre['dx']) + $sx, 'dy' => ($round === 0 ? 0 : $centre['dy']) + $sy];
                        $e = $this->placementError($luma, $cutout, $key, $w, $h, $t);
                        if ($e < $best['error']) {
                            $best = $t + ['error' => $e];
                        }
                    }
                }
            }
        }

        return $best;
    }

    /** Cut-out drawn into the original frame (scale around the centre, then shift), background = key colour. */
    private function placed(ImageEditor $cutout, array $key, int $width, int $height, ?array $t): GdImage
    {
        if ($t === null || ($t['scale'] == 1.0 && $t['dx'] == 0.0 && $t['dy'] == 0.0)) {
            return $cutout->copy()->resize($width, $height)->gd();
        }

        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...$key));
        $dw = (int) round($width * $t['scale']);
        $dh = (int) round($height * $t['scale']);
        $dx = (int) round(($width - $dw) / 2 + $t['dx'] * $width);
        $dy = (int) round(($height - $dh) / 2 + $t['dy'] * $height);
        $src = $cutout->gd();
        imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, imagesx($src), imagesy($src));

        return $canvas;
    }

    /** Mean luma difference inside the (placed) cut-out product, 0..1; 1 when there is no product. */
    private function placementError(array $luma, ImageEditor $cutout, array $key, int $w, int $h, ?array $t): float
    {
        $placed = $this->placed($cutout, $key, $w, $h, $t);
        $diff = 0.0;
        $n = 0;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($placed, $x, $y);
                if ($this->distance([($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF], $key) < 0.5) {
                    continue; // background
                }
                $diff += abs($luma[$y][$x] - $this->luma($c));
                $n++;
            }
        }

        return $n > ($w * $h * 0.005) ? $diff / $n / 255 : 1.0;
    }

    /**
     * Product over background. $background: GdImage (same size), an RGB array
     * for a flat colour, 'neutral' for a light studio backdrop with a soft
     * contact shadow, or null for transparent.
     */
    public function compose(ImageEditor $product, GdImage $mask, GdImage|array|string|null $background): ImageEditor
    {
        $p = $product->gd();
        $w = imagesx($p);
        $h = imagesy($p);

        if (imagesx($mask) !== $w || imagesy($mask) !== $h) {
            throw new RuntimeException('Mask size does not match');
        }

        $bg = match (true) {
            $background === 'neutral' => $this->neutralBackdrop($w, $h, $mask),
            is_array($background) => $this->flat($w, $h, $background),
            $background instanceof GdImage => $background,
            default => null,
        };

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $a = (imagecolorat($mask, $x, $y) & 0xFF) / 255;
                $pc = imagecolorat($p, $x, $y);

                if ($bg === null) {
                    $alpha = (int) round((1 - $a) * 127);
                    imagesetpixel($out, $x, $y, ($alpha << 24) | ($pc & 0xFFFFFF));

                    continue;
                }

                if ($a >= 0.999) {
                    imagesetpixel($out, $x, $y, $pc & 0xFFFFFF);

                    continue;
                }

                $bc = imagecolorat($bg, $x, $y);
                $r = (int) round((($pc >> 16) & 0xFF) * $a + (($bc >> 16) & 0xFF) * (1 - $a));
                $g = (int) round((($pc >> 8) & 0xFF) * $a + (($bc >> 8) & 0xFF) * (1 - $a));
                $b = (int) round(($pc & 0xFF) * $a + ($bc & 0xFF) * (1 - $a));
                imagesetpixel($out, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }

        return ImageEditor::fromGd($out);
    }

    /**
     * Strongly blurred background (depth-of-field look). With a mask the
     * product is left out before blurring (normalised blur), so its colour
     * does not bleed into the background as a halo.
     */
    public function blurred(ImageEditor $photo, ?GdImage $mask = null): GdImage
    {
        $w = $photo->width();
        $h = $photo->height();
        $small = $photo->copy()->fitWithin(max(32, (int) round(max($w, $h) / 12)))->gd();
        $sw = imagesx($small);
        $sh = imagesy($small);

        if ($mask === null) {
            for ($i = 0; $i < 4; $i++) {
                imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
            }

            return imagescale($small, $w, $h, IMG_BICUBIC);
        }

        // Weighted colours (background only) and the weights, blurred alike, then divided.
        $m = imagescale($mask, $sw, $sh);
        $num = imagecreatetruecolor($sw, $sh);
        $wgt = imagecreatetruecolor($sw, $sh);
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $k = 1 - (imagecolorat($m, $x, $y) & 0xFF) / 255;
                $c = imagecolorat($small, $x, $y);
                imagesetpixel($num, $x, $y, ((int) ((($c >> 16) & 0xFF) * $k) << 16) | ((int) ((($c >> 8) & 0xFF) * $k) << 8) | (int) (($c & 0xFF) * $k));
                $v = (int) round($k * 255);
                imagesetpixel($wgt, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }
        for ($i = 0; $i < 6; $i++) {
            imagefilter($num, IMG_FILTER_GAUSSIAN_BLUR);
            imagefilter($wgt, IMG_FILTER_GAUSSIAN_BLUR);
        }
        $out = imagecreatetruecolor($sw, $sh);
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $k = max(1, imagecolorat($wgt, $x, $y) & 0xFF) / 255;
                $c = imagecolorat($num, $x, $y);
                $r = min(255, (int) ((($c >> 16) & 0xFF) / $k));
                $g = min(255, (int) ((($c >> 8) & 0xFF) / $k));
                $b = min(255, (int) (($c & 0xFF) / $k));
                imagesetpixel($out, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }
        for ($i = 0; $i < 2; $i++) {
            imagefilter($out, IMG_FILTER_GAUSSIAN_BLUR);
        }

        return imagescale($out, $w, $h, IMG_BICUBIC);
    }

    private function neutralBackdrop(int $w, int $h, GdImage $mask): GdImage
    {
        $bg = imagecreatetruecolor($w, $h);
        // Soft vertical gradient, light studio grey.
        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            $v = (int) round(246 - 16 * $t);
            imageline($bg, 0, $y, $w - 1, $y, imagecolorallocate($bg, $v, $v, $v - 2));
        }

        // Contact shadow: blurred mask, shifted down a little, darkening up to 22 %.
        $small = imagescale($mask, max(16, (int) round($w / 10)), max(16, (int) round($h / 10)));
        for ($i = 0; $i < 3; $i++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }
        $shadow = imagescale($small, $w, $h, IMG_BICUBIC);
        $shift = (int) round($h * 0.012);

        for ($y = 0; $y < $h; $y++) {
            $sy = max(0, $y - $shift);
            for ($x = 0; $x < $w; $x++) {
                $s = (imagecolorat($shadow, $x, $sy) & 0xFF) / 255;
                if ($s < 0.02) {
                    continue;
                }
                $c = imagecolorat($bg, $x, $y);
                $k = 1 - 0.22 * $s;
                imagesetpixel($bg, $x, $y, ((int) ((($c >> 16) & 0xFF) * $k) << 16) | ((int) ((($c >> 8) & 0xFF) * $k) << 8) | (int) (($c & 0xFF) * $k));
            }
        }

        return $bg;
    }

    private function flat(int $w, int $h, array $rgb): GdImage
    {
        $bg = imagecreatetruecolor($w, $h);
        imagefill($bg, 0, 0, imagecolorallocate($bg, ...$rgb));

        return $bg;
    }

    /** 1-2 px feather so edges blend naturally. */
    private function soften(GdImage $mask): GdImage
    {
        imagefilter($mask, IMG_FILTER_GAUSSIAN_BLUR);

        return $mask;
    }

    private function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2) / 441.673;
    }

    private function luma(int $c): float
    {
        return 0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF);
    }
}
