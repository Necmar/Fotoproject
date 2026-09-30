<?php

namespace Tests\Unit;

use App\Services\Images\DetailRestorer;
use App\Services\Images\ImageEditor;
use PHPUnit\Framework\TestCase;

class DetailRestorerTest extends TestCase
{
    private function photo(int $dx, int $dy, array $label, int $bg = 120): ImageEditor
    {
        $img = imagecreatetruecolor(400, 300);
        imagefill($img, 0, 0, imagecolorallocate($img, $bg, $bg, $bg));
        // A "screen" with a line of text on it.
        imagefilledrectangle($img, 80 + $dx, 80 + $dy, 260 + $dx, 180 + $dy, imagecolorallocate($img, 60, 60, 60));
        imagefilledrectangle($img, 120 + $dx, 120 + $dy, 200 + $dx, 135 + $dy, imagecolorallocate($img, ...$label));

        return ImageEditor::fromGd($img);
    }

    public function test_restores_the_original_detail_aligned_and_in_the_retouched_tone(): void
    {
        $original = $this->photo(0, 0, [240, 240, 240]);
        // The edit moved the picture a few pixels, made it brighter and "rewrote" the text in another colour.
        $edited = $this->photo(4, 3, [240, 40, 40], 140);

        $done = (new DetailRestorer)->restore($edited, $original, [['x' => 110 / 400, 'y' => 112 / 300, 'width' => 100 / 400, 'height' => 32 / 300]]);

        $this->assertSame(1, $done);
        $c = imagecolorat($edited->gd(), 164, 130); // centre of the (shifted) text line
        $this->assertGreaterThan(200, $c & 0xFF, 'text is white again, not red');
        $this->assertGreaterThan(200, ($c >> 8) & 0xFF);
        // No seam: the screen next to the text keeps the edited tone.
        $s = imagecolorat($edited->gd(), 100, 150);
        $this->assertEqualsWithDelta(60, $s & 0xFF, 6);
        // Outside the region nothing changes.
        $this->assertSame(140, imagecolorat($edited->gd(), 10, 10) & 0xFF);
    }

    public function test_ignores_empty_or_huge_regions(): void
    {
        $edited = $this->photo(0, 0, [240, 40, 40]);
        $done = (new DetailRestorer)->restore($edited, $this->photo(0, 0, [240, 240, 240]), [
            ['x' => 0.1, 'y' => 0.1, 'width' => 0, 'height' => 0.2],
            ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
        ]);

        $this->assertSame(0, $done);
    }
}
