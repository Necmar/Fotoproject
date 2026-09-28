<?php

namespace App\Services\Images;

/**
 * Detects the real image type from file contents (never from the extension
 * or the client-supplied MIME type).
 */
class ImageTypeDetector
{
    public const JPEG = 'image/jpeg';

    public const PNG = 'image/png';

    public const HEIC = 'image/heic';

    public const HEIF = 'image/heif';

    /** ISO-BMFF brands used by HEIC/HEIF files from iPhones and other devices. */
    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs'];

    private const HEIF_BRANDS = ['mif1', 'msf1', 'mif2'];

    /** @return list<string> */
    public static function allowed(): array
    {
        return [self::JPEG, self::PNG, self::HEIC, self::HEIF];
    }

    /** Returns one of the allowed MIME types, or null when the file is not a supported image. */
    public function detect(string $path): ?string
    {
        $header = @file_get_contents($path, false, null, 0, 64);

        if ($header === false || strlen($header) < 12) {
            return null;
        }

        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return self::JPEG;
        }

        if (str_starts_with($header, "\x89PNG\r\n\x1A\n")) {
            return self::PNG;
        }

        // ISO-BMFF: [size:4]['ftyp'][major brand:4][minor:4][compatible brands...]
        if (substr($header, 4, 4) === 'ftyp') {
            $boxSize = unpack('N', substr($header, 0, 4))[1];
            $brands = [substr($header, 8, 4)];

            for ($offset = 16; $offset + 4 <= min($boxSize, strlen($header)); $offset += 4) {
                $brands[] = substr($header, $offset, 4);
            }

            if (array_intersect($brands, self::HEIC_BRANDS)) {
                return self::HEIC;
            }

            if (array_intersect($brands, self::HEIF_BRANDS)) {
                return self::HEIF;
            }
        }

        return null;
    }

    public function isHeif(?string $mime): bool
    {
        return $mime === self::HEIC || $mime === self::HEIF;
    }

    public static function extensionFor(string $mime): string
    {
        return match ($mime) {
            self::JPEG => 'jpg',
            self::PNG => 'png',
            self::HEIC => 'heic',
            self::HEIF => 'heif',
            default => 'bin',
        };
    }
}
