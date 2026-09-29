<?php

namespace App\Services\Images;

use App\Enums\OptimizationStrength;

/**
 * Fast, non-AI image analysis and correction on a small sample:
 * exposure, contrast, colour cast and sharpness. Used for
 *  - early warnings (too dark, too light, low contrast, possibly blurry),
 *  - mild automatic corrections when AI is off or an AI edit failed,
 *  - extra input for the AI prompt (phase 5).
 */
class LocalEnhancer
{
    /** @return array<string, float|bool> metrics in 0..1 (sharpness: Laplacian variance) */
    public function analyze(ImageEditor $image): array
    {
        $sample = $image->copy()->fitWithin(256)->gd();
        $w = imagesx($sample);
        $h = imagesy($sample);

        $sumL = $sumL2 = 0.0;
        $sumR = $sumG = $sumB = 0.0;
        $nR = $nG = $nB = 0.0;
        $neutral = 0;
        $dark = $bright = 0;
        $gray = [];

        $histogram = array_fill(0, 256, 0);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($sample, $x, $y);
                $r = (($rgb >> 16) & 0xFF) / 255;
                $g = (($rgb >> 8) & 0xFF) / 255;
                $b = ($rgb & 0xFF) / 255;
                $l = 0.299 * $r + 0.587 * $g + 0.114 * $b;

                $sumL += $l;
                $sumL2 += $l * $l;
                $sumR += $r;
                $sumG += $g;
                $sumB += $b;
                // Near-grey, mid-tone pixels tell us the colour cast without
                // being fooled by a large coloured product (e.g. a red car).
                if (max($r, $g, $b) - min($r, $g, $b) < 0.15 && $l > 0.15 && $l < 0.9) {
                    $nR += $r;
                    $nG += $g;
                    $nB += $b;
                    $neutral++;
                }

                $histogram[(int) round($l * 255)]++;
                $dark += $l < 0.06 ? 1 : 0;
                $bright += $l > 0.96 ? 1 : 0;
                $gray[$y][$x] = $l * 255;
            }
        }

        $n = $w * $h;
        $mean = $sumL / $n;
        [$p05, $p50, $p95] = $this->percentiles($histogram, $n, [0.05, 0.5, 0.95]);

        return [
            'brightness' => round($mean, 4),
            'contrast' => round(sqrt(max(0, $sumL2 / $n - $mean ** 2)), 4),
            'red' => round($sumR / $n, 4),
            'green' => round($sumG / $n, 4),
            'blue' => round($sumB / $n, 4),
            'neutral_ratio' => round($neutral / $n, 4),
            'neutral_red' => $neutral ? round($nR / $neutral, 4) : null,
            'neutral_green' => $neutral ? round($nG / $neutral, 4) : null,
            'neutral_blue' => $neutral ? round($nB / $neutral, 4) : null,
            'clipped_dark' => round($dark / $n, 4),
            'clipped_bright' => round($bright / $n, 4),
            // Tonal range: a washed-out photo has no dark tones left anywhere,
            // an underexposed one no light tones.
            'p05' => $p05,
            'p50' => $p50,
            'p95' => $p95,
            'sharpness' => round($this->laplacianVariance($gray, $w, $h), 2),
        ];
    }

    /**
     * Warnings a user should see even without AI (codes are translated in the UI).
     *
     * @return list<array{code: string}>
     */
    public function warnings(array $m): array
    {
        $warnings = [];

        // Judged on the whole tonal range, not on how much is white or black:
        // a white background, white tiles or a screenshot still contain dark
        // detail (text, product, edges), a truly overexposed photo does not.
        // Same for a product on a black background versus an underexposed photo.
        if (isset($m['p05'], $m['p50'], $m['p95'])) {
            if ($m['p95'] < 0.35 && $m['p50'] < 0.2) {
                $warnings[] = ['code' => 'too_dark'];
            } elseif ($m['p05'] > 0.4 && $m['p50'] > 0.8) {
                $warnings[] = ['code' => 'too_bright'];
            }
        }

        if ($m['contrast'] < 0.08) {
            $warnings[] = ['code' => 'low_contrast'];
        }

        if ($m['sharpness'] < (float) config('bora.processing.blur_threshold', 18)) {
            $warnings[] = ['code' => 'possibly_blurry'];
        }

        return $warnings;
    }

    /**
     * Mild global corrections. Deliberately conservative: never changes the
     * product colour beyond neutralising an obvious colour cast.
     *
     * @return array{gamma: float, contrast: int, red: int, green: int, blue: int, sharpen: float}
     */
    public function adjustments(array $m, OptimizationStrength $strength): array
    {
        $k = match ($strength) {
            OptimizationStrength::Subtle => 0.5,
            OptimizationStrength::Normal => 1.0,
            OptimizationStrength::Strong => 1.4,
        };

        // Exposure: move the mean luminance towards ~0.5 with a gamma curve.
        $target = 0.5;
        $mean = min(0.95, max(0.05, $m['brightness']));
        $gamma = log($target) / log($mean);
        $gamma = 1 + ($this->clamp($gamma, 0.7, 1.35) - 1) * $k * 0.6;

        // Contrast: GD uses negative values for more contrast.
        $contrast = $m['contrast'] < 0.2 ? (int) round(-12 * $k * (0.2 - $m['contrast']) / 0.2) : 0;

        // White balance only from near-neutral pixels, and only when there are
        // enough of them; otherwise leave colours alone (product colour first).
        $wb = ['red' => 0, 'green' => 0, 'blue' => 0];
        if (($m['neutral_ratio'] ?? 0) >= 0.05 && isset($m['neutral_red'])) {
            $avg = ($m['neutral_red'] + $m['neutral_green'] + $m['neutral_blue']) / 3;
            foreach (['red', 'green', 'blue'] as $c) {
                $wb[$c] = (int) round($this->clamp(($avg - $m['neutral_'.$c]) * 255 * 0.6 * $k, -12, 12));
            }
        }

        return [
            'gamma' => round($gamma, 3),
            'contrast' => $contrast,
            'red' => $wb['red'],
            'green' => $wb['green'],
            'blue' => $wb['blue'],
            'sharpen' => round(0.12 * $k, 3),
        ];
    }

    /**
     * @param  array<int, int>  $histogram  256 luminance bins
     * @param  list<float>  $quantiles
     * @return list<float> luminance (0..1) at each quantile
     */
    private function percentiles(array $histogram, int $n, array $quantiles): array
    {
        $result = [];
        $cumulative = 0;
        $bin = 0;

        foreach ($quantiles as $q) {
            $target = $q * $n;
            while ($bin < 255 && $cumulative + $histogram[$bin] < $target) {
                $cumulative += $histogram[$bin];
                $bin++;
            }
            $result[] = round($bin / 255, 4);
        }

        return $result;
    }

    private function laplacianVariance(array $gray, int $w, int $h): float
    {
        $sum = $sum2 = 0.0;
        $n = 0;

        for ($y = 1; $y < $h - 1; $y++) {
            for ($x = 1; $x < $w - 1; $x++) {
                $lap = $gray[$y - 1][$x] + $gray[$y + 1][$x] + $gray[$y][$x - 1] + $gray[$y][$x + 1] - 4 * $gray[$y][$x];
                $sum += $lap;
                $sum2 += $lap * $lap;
                $n++;
            }
        }

        if ($n === 0) {
            return 0.0;
        }

        return $sum2 / $n - ($sum / $n) ** 2;
    }

    private function clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }
}
