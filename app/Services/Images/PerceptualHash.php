<?php

namespace App\Services\Images;

/**
 * 64-bit difference hash (dHash). Nearly identical photos (same shot,
 * re-saved, slightly resized) have a small Hamming distance.
 */
class PerceptualHash
{
    public static function fromEditor(ImageEditor $image): string
    {
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $image->gd(), 0, 0, 0, 0, 9, 8, $image->width(), $image->height());

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            $prev = null;
            for ($x = 0; $x < 9; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $lum = (($rgb >> 16) & 0xFF) * 299 + (($rgb >> 8) & 0xFF) * 587 + ($rgb & 0xFF) * 114;
                if ($prev !== null) {
                    $bits .= $lum > $prev ? '1' : '0';
                }
                $prev = $lum;
            }
        }

        return str_pad(base_convert(substr($bits, 0, 32), 2, 16), 8, '0', STR_PAD_LEFT)
            .str_pad(base_convert(substr($bits, 32), 2, 16), 8, '0', STR_PAD_LEFT);
    }

    public static function distance(string $a, string $b): int
    {
        $distance = 0;
        foreach ([0, 8] as $offset) {
            $distance += substr_count(decbin(hexdec(substr($a, $offset, 8)) ^ hexdec(substr($b, $offset, 8))), '1');
        }

        return $distance;
    }
}
