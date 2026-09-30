<?php

namespace App\Services\Images;

use GdImage;

/**
 * Puts the ORIGINAL pixels back in the places where an AI edit changed the
 * product (text, displays, logos, licence plate, damage, parts), instead of
 * rejecting the whole edit. The rest of the AI retouch stays.
 *
 * Per region: the original patch is aligned to the edit (small shift search,
 * the image model sometimes moves the picture a few pixels), its tones are
 * matched to the retouch by comparing the unchanged surroundings (the band
 * around the box) in both images, and it is
 * blended in with a soft edge. Alpha of the edit is kept (transparent PNG),
 * and with a product mask only product pixels are restored.
 */
class DetailRestorer
{
    /**
     * @param  list<array{x: float, y: float, width: float, height: float}>  $regions  fractions (0..1) of the edited image
     * @return int number of regions restored
     */
    public function restore(ImageEditor $edited, ImageEditor $original, array $regions, ?GdImage $mask = null): int
    {
        $e = $edited->gd();
        $w = imagesx($e);
        $h = imagesy($e);
        $o = $original->copy();
        if ($o->width() !== $w || $o->height() !== $h) {
            $o->resize($w, $h);
        }
        $og = $o->gd();
        imagealphablending($e, false);
        imagesavealpha($e, true);

        $done = 0;
        foreach ($regions as $region) {
            $box = $this->pixelBox($region, $w, $h);
            if ($box === null) {
                continue;
            }

            [$dx, $dy] = $this->align($e, $og, $box, $w, $h);
            $this->blend($e, $og, $box, $dx, $dy, $w, $h, $mask);
            $done++;
        }

        return $done;
    }

    /** Region as pixels, with padding for the (rough) boxes the check gives. */
    private function pixelBox(array $r, int $w, int $h): ?array
    {
        $x = max(0.0, min(1.0, (float) ($r['x'] ?? 0)));
        $y = max(0.0, min(1.0, (float) ($r['y'] ?? 0)));
        $rw = max(0.0, min(1.0 - $x, (float) ($r['width'] ?? 0)));
        $rh = max(0.0, min(1.0 - $y, (float) ($r['height'] ?? 0)));
        if ($rw * $rh <= 0.00001 || $rw * $rh > 0.6) {
            return null; // nothing, or so large it would undo the whole retouch
        }

        $x0 = $x * $w;
        $y0 = $y * $h;
        $x1 = ($x + $rw) * $w;
        $y1 = ($y + $rh) * $h;
        $pad = max(6, 0.15 * min($x1 - $x0, $y1 - $y0));
        $feather = max(4, (int) round(0.35 * $pad));

        return [
            'ix0' => (int) floor($x0), 'iy0' => (int) floor($y0), 'ix1' => (int) ceil($x1), 'iy1' => (int) ceil($y1),
            'x0' => (int) max(0, floor($x0 - $pad)), 'y0' => (int) max(0, floor($y0 - $pad)),
            'x1' => (int) min($w - 1, ceil($x1 + $pad)), 'y1' => (int) min($h - 1, ceil($y1 + $pad)),
            'feather' => $feather,
        ];
    }

    /** Small shift of the original that fits the edit best (structure only, brightness ignored). */
    private function align(GdImage $e, GdImage $o, array $box, int $w, int $h): array
    {
        $bw = $box['x1'] - $box['x0'];
        $bh = $box['y1'] - $box['y0'];
        $r = (int) min(24, round(0.012 * max($w, $h)) + 3);
        $stride = max(1, (int) floor(sqrt(($bw * $bh) / 2500)));

        $ev = [];
        for ($y = $box['y0']; $y <= $box['y1']; $y += $stride) {
            for ($x = $box['x0']; $x <= $box['x1']; $x += $stride) {
                $ev[] = $this->lum(imagecolorat($e, $x, $y));
            }
        }
        $em = array_sum($ev) / max(1, count($ev));

        $score = function (int $dx, int $dy) use ($o, $box, $stride, $ev, $em, $w, $h): float {
            $ov = [];
            for ($y = $box['y0']; $y <= $box['y1']; $y += $stride) {
                for ($x = $box['x0']; $x <= $box['x1']; $x += $stride) {
                    $ov[] = $this->lum(imagecolorat($o, max(0, min($w - 1, $x + $dx)), max(0, min($h - 1, $y + $dy))));
                }
            }
            $om = array_sum($ov) / max(1, count($ov));
            $sum = 0.0;
            foreach ($ov as $i => $v) {
                $sum += abs(($v - $om) - ($ev[$i] - $em));
            }

            return $sum / max(1, count($ov));
        };

        $best = [0, 0];
        $bestScore = $score(0, 0);
        foreach ([max(2, intdiv($r, 4)), 1] as $step) {
            [$cx, $cy] = $best;
            $reach = $step === 1 ? max(2, intdiv($r, 4)) : $r;
            for ($dy = -$reach; $dy <= $reach; $dy += $step) {
                for ($dx = -$reach; $dx <= $reach; $dx += $step) {
                    $s = $score($cx + $dx, $cy + $dy);
                    if ($s < $bestScore - 0.01) {
                        $bestScore = $s;
                        $best = [$cx + $dx, $cy + $dy];
                    }
                }
            }
        }

        return $best;
    }

    private function blend(GdImage $e, GdImage $o, array $box, int $dx, int $dy, int $w, int $h, ?GdImage $mask): void
    {
        // Tone matching: what the retouch did to the surroundings (the band around
        // the changed area) is applied to the original patch.
        $es = $os = [[0, 0], [0, 0], [0, 0]];
        $n = 0;
        for ($y = $box['y0']; $y <= $box['y1']; $y += 2) {
            for ($x = $box['x0']; $x <= $box['x1']; $x += 2) {
                if ($x >= $box['ix0'] && $x <= $box['ix1'] && $y >= $box['iy0'] && $y <= $box['iy1']) {
                    continue;
                }
                $ec = imagecolorat($e, $x, $y);
                $oc = imagecolorat($o, max(0, min($w - 1, $x + $dx)), max(0, min($h - 1, $y + $dy)));
                foreach ([16, 8, 0] as $i => $shift) {
                    $a = ($ec >> $shift) & 0xFF;
                    $b = ($oc >> $shift) & 0xFF;
                    $es[$i][0] += $a;
                    $es[$i][1] += $a * $a;
                    $os[$i][0] += $b;
                    $os[$i][1] += $b * $b;
                }
                $n++;
            }
        }
        $map = [];
        foreach ([0, 1, 2] as $i) {
            if ($n < 30) {
                $map[$i] = [0, 1.0, 0]; // no surroundings to learn from: original as is
                continue;
            }
            $em = $es[$i][0] / $n;
            $om = $os[$i][0] / $n;
            $esd = sqrt(max(0, $es[$i][1] / $n - $em * $em));
            $osd = sqrt(max(0, $os[$i][1] / $n - $om * $om));
            $k = ($esd < 4 || $osd < 4) ? 1.0 : max(0.8, min(1.25, $esd / $osd));
            $map[$i] = [$om, $k, $em];
        }

        $f = $box['feather'];
        for ($y = $box['y0']; $y <= $box['y1']; $y++) {
            for ($x = $box['x0']; $x <= $box['x1']; $x++) {
                $edge = min($x - $box['x0'], $box['x1'] - $x, $y - $box['y0'], $box['y1'] - $y);
                $weight = min(1.0, ($edge + 1) / $f);
                if ($mask) {
                    $weight *= (imagecolorat($mask, $x, $y) & 0xFF) / 255;
                }
                if ($weight <= 0) {
                    continue;
                }

                $ec = imagecolorat($e, $x, $y);
                $oc = imagecolorat($o, max(0, min($w - 1, $x + $dx)), max(0, min($h - 1, $y + $dy)));
                $rgb = 0;
                foreach ([16, 8, 0] as $i => $shift) {
                    [$om, $k, $em] = $map[$i];
                    $restored = ((($oc >> $shift) & 0xFF) - $om) * $k + $em;
                    $v = (int) round(max(0, min(255, $restored * $weight + (($ec >> $shift) & 0xFF) * (1 - $weight))));
                    $rgb |= $v << $shift;
                }
                imagesetpixel($e, $x, $y, ($ec & 0x7F000000) | $rgb);
            }
        }
    }

    private function lum(int $c): float
    {
        return 0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF);
    }
}
