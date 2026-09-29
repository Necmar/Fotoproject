<?php

namespace Tests\Unit;

use App\Enums\OptimizationStrength;
use App\Services\Images\ImageEditor;
use App\Services\Images\PhotoFinisher;
use PHPUnit\Framework\TestCase;

class PhotoFinisherTest extends TestCase
{
    public function test_a_flat_hazy_photo_gets_deeper_blacks_and_brighter_whites(): void
    {
        $gd = imagecreatetruecolor(200, 100);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 120, 120, 120));
        imagefilledrectangle($gd, 0, 0, 99, 99, imagecolorallocate($gd, 40, 40, 40));
        imagefilledrectangle($gd, 100, 0, 199, 99, imagecolorallocate($gd, 200, 200, 200));

        $out = (new PhotoFinisher)->finish(ImageEditor::fromGd($gd), OptimizationStrength::Normal)->gd();

        $dark = imagecolorat($out, 50, 50);
        $light = imagecolorat($out, 150, 50);
        $this->assertLessThan(40, $dark & 0xFF);
        $this->assertGreaterThan(200, $light & 0xFF);
        // Neutral stays neutral: no colour shift.
        $this->assertSame(($light >> 16) & 0xFF, $light & 0xFF);
    }

    public function test_transparency_is_kept(): void
    {
        $gd = imagecreatetruecolor(60, 60);
        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        imagefill($gd, 0, 0, imagecolorallocatealpha($gd, 0, 0, 0, 127));
        imagefilledrectangle($gd, 20, 20, 40, 40, imagecolorallocatealpha($gd, 180, 40, 40, 0));

        $out = (new PhotoFinisher)->finish(ImageEditor::fromGd($gd), OptimizationStrength::Strong)->gd();

        $this->assertSame(127, (imagecolorat($out, 2, 2) >> 24) & 0x7F);
        $this->assertSame(0, (imagecolorat($out, 30, 30) >> 24) & 0x7F);
    }
}
