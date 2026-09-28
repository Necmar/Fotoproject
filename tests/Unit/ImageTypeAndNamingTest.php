<?php

namespace Tests\Unit;

use App\Services\Images\ImageTypeDetector;
use App\Support\FileNamer;
use PHPUnit\Framework\TestCase;

class ImageTypeAndNamingTest extends TestCase
{
    private function detect(string $bytes): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $bytes);

        try {
            return (new ImageTypeDetector)->detect($path);
        } finally {
            unlink($path);
        }
    }

    public function test_detects_types_from_magic_bytes(): void
    {
        $this->assertSame('image/jpeg', $this->detect("\xFF\xD8\xFF\xE0".str_repeat("\x00", 20)));
        $this->assertSame('image/png', $this->detect("\x89PNG\r\n\x1A\n".str_repeat("\x00", 20)));
        $this->assertSame('image/heic', $this->detect("\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x00", 8)));
        $this->assertSame('image/heif', $this->detect("\x00\x00\x00\x14ftypmif1\x00\x00\x00\x00mif1".str_repeat("\x00", 8)));
        $this->assertNull($this->detect("\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00isommp42".str_repeat("\x00", 8)));
        $this->assertNull($this->detect('GIF89a'.str_repeat("\x00", 20)));
        $this->assertNull($this->detect('%PDF-1.7'.str_repeat("\x00", 20)));
        $this->assertNull($this->detect('x'));
    }

    public function test_file_names(): void
    {
        $this->assertSame('bmw-320i', FileNamer::base('BMW 320i', 'foto'));
        $this->assertSame('citroen-c3-ete', FileNamer::base('Citroën C3 été', null));
        $this->assertSame('garage', FileNamer::base('  ', 'garage'));
        $this->assertSame('foto', FileNamer::base('../../', '***'));
        $this->assertSame('bmw-320i-01.jpg', FileNamer::numbered('bmw-320i', 1, 'jpg'));
        $this->assertSame('foto-30.png', FileNamer::numbered('foto', 30, 'png'));
    }
}
