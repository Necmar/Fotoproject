<?php

namespace Tests\Feature;

use App\Services\Images\ImageEditor;
use App\Services\Images\LocalEnhancer;
use Tests\TestCase;

/** Local (non-AI) exposure warnings: judged on tonal range, not on the amount of white or black. */
class ExposureCheckTest extends TestCase
{
    private function codes(\GdImage $img): array
    {
        $enhancer = app(LocalEnhancer::class);

        return array_column($enhancer->warnings($enhancer->analyze(ImageEditor::fromGd($img))), 'code');
    }

    /** Vertical gradient between two grey levels (0..255), with some texture. */
    private function gradient(int $from, int $to): \GdImage
    {
        $img = imagecreatetruecolor(800, 600);
        for ($y = 0; $y < 600; $y++) {
            $v = (int) round($from + ($to - $from) * $y / 599);
            imageline($img, 0, $y, 799, $y, imagecolorallocate($img, $v, $v, $v));
        }
        for ($i = 0; $i < 40; $i++) {
            $v = random_int($from, $to);
            imagefilledellipse($img, random_int(0, 800), random_int(0, 600), 60, 40, imagecolorallocate($img, $v, $v, $v));
        }

        return $img;
    }

    public function test_white_background_with_product_or_text_is_not_overexposed(): void
    {
        // Screenshot-like: mostly pure white, dark headline text and a dark button.
        $img = imagecreatetruecolor(800, 600);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledrectangle($img, 60, 80, 700, 150, imagecolorallocate($img, 40, 45, 60));
        imagefilledrectangle($img, 60, 420, 300, 470, imagecolorallocate($img, 10, 10, 10));
        $this->assertNotContains('too_bright', $this->codes($img));

        // Product photo on a white background.
        $img = imagecreatetruecolor(800, 600);
        imagefill($img, 0, 0, imagecolorallocate($img, 252, 252, 252));
        imagefilledrectangle($img, 250, 150, 550, 450, imagecolorallocate($img, 120, 30, 30));
        $this->assertNotContains('too_bright', $this->codes($img));
    }

    public function test_washed_out_photo_is_overexposed(): void
    {
        $this->assertContains('too_bright', $this->codes($this->gradient(200, 255)));
    }

    public function test_black_background_with_bright_product_is_not_underexposed(): void
    {
        $img = imagecreatetruecolor(800, 600);
        imagefill($img, 0, 0, imagecolorallocate($img, 0, 0, 0));
        imagefilledrectangle($img, 250, 150, 550, 450, imagecolorallocate($img, 230, 200, 60));
        $this->assertNotContains('too_dark', $this->codes($img));
    }

    public function test_underexposed_photo_is_too_dark(): void
    {
        $this->assertContains('too_dark', $this->codes($this->gradient(0, 60)));
    }

    public function test_normal_photo_gets_no_exposure_warning(): void
    {
        $codes = $this->codes($this->gradient(20, 235));
        $this->assertNotContains('too_bright', $codes);
        $this->assertNotContains('too_dark', $codes);
    }
}
