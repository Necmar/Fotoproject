<?php

namespace App\Services\Images;

/**
 * Reads the EXIF Orientation tag (1..8) from a JPEG without needing the
 * exif PHP extension, which is not always enabled on shared hosting.
 */
class ExifOrientation
{
    public static function read(string $path): int
    {
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return 1;
        }

        try {
            if (fread($fh, 2) !== "\xFF\xD8") {
                return 1;
            }

            // Walk JPEG segments until APP1/Exif or start of scan.
            while (! feof($fh)) {
                $marker = fread($fh, 2);
                if (strlen($marker) < 2 || $marker[0] !== "\xFF") {
                    return 1;
                }

                $code = ord($marker[1]);
                if ($code === 0xDA || $code === 0xD9) {
                    return 1; // image data starts; no EXIF before it
                }

                $lengthBytes = fread($fh, 2);
                if (strlen($lengthBytes) < 2) {
                    return 1;
                }
                $length = unpack('n', $lengthBytes)[1] - 2;

                if ($code === 0xE1 && $length > 14) {
                    $data = fread($fh, min($length, 65533));

                    if (str_starts_with($data, "Exif\0\0")) {
                        return self::fromTiff(substr($data, 6));
                    }

                    continue;
                }

                fseek($fh, $length, SEEK_CUR);
            }

            return 1;
        } finally {
            fclose($fh);
        }
    }

    private static function fromTiff(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }

        $little = substr($tiff, 0, 2) === 'II';
        $u16 = fn (int $o) => $o + 2 <= strlen($tiff) ? unpack($little ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0;
        $u32 = fn (int $o) => $o + 4 <= strlen($tiff) ? unpack($little ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0;

        $ifd = $u32(4);
        $entries = $u16($ifd);

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;

            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}
