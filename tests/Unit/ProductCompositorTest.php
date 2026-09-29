<?php

namespace Tests\Unit;

use App\Services\Images\ImageEditor;
use App\Services\Images\ProductCompositor;
use PHPUnit\Framework\TestCase;

class ProductCompositorTest extends TestCase
{
    private function scene(int $w, int $h, float $scale, int $dx, int $dy, ?array $bg): ImageEditor
    {
        $gd = imagecreatetruecolor($w, $h);
        imagefill($gd, 0, 0, $bg ? imagecolorallocate($gd, ...$bg) : imagecolorallocate($gd, 120, 140, 160));
        if (! $bg) {
            imagefilledrectangle($gd, 0, (int) ($h * 0.7), $w, $h, imagecolorallocate($gd, 60, 60, 60));
        }
        $cx = $w / 2 + $dx;
        $cy = $h / 2 + $dy;
        $rw = 180 * $scale;
        $rh = 110 * $scale;
        imagefilledrectangle($gd, (int) ($cx - $rw / 2), (int) ($cy - $rh / 2), (int) ($cx + $rw / 2), (int) ($cy + $rh / 2), imagecolorallocate($gd, 200, 30, 30));
        imagefilledellipse($gd, (int) ($cx - $rw / 4), (int) ($cy + $rh / 2), (int) (50 * $scale), (int) (50 * $scale), imagecolorallocate($gd, 20, 20, 20));
        imagefilledellipse($gd, (int) ($cx + $rw / 4), (int) ($cy + $rh / 2), (int) (50 * $scale), (int) (50 * $scale), imagecolorallocate($gd, 20, 20, 20));

        return ImageEditor::fromGd($gd);
    }

    public function test_registration_recovers_shift_scale_and_off_key_colour(): void
    {
        $c = new ProductCompositor;
        $original = $this->scene(480, 360, 1.0, 0, 0, null);
        // The AI returned a slightly zoomed, shifted cut-out on a not-quite-exact magenta.
        $cutout = $this->scene(480, 360, 1.04, 14, -9, [236, 12, 244]);

        $key = $c->measuredKey($cutout, [255, 0, 255]);
        $this->assertEqualsWithDelta(236, $key[0], 6);

        $placement = $c->register($original, $cutout, $key);
        $this->assertLessThan(0.16, $placement['error']);

        ['coverage' => $coverage] = $c->maskFromCutout($cutout, $key, 480, 360, $placement);
        $this->assertGreaterThan(0.05, $coverage);
        $this->assertLessThan(0.5, $coverage);
    }
}
