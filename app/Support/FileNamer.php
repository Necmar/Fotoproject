<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Safe, readable file names: "BMW 320i" becomes bmw-320i-01.jpg, bmw-320i-02.jpg, ... */
final class FileNamer
{
    public const MAX_BASE_LENGTH = 60;

    /** Base name from the batch name, falling back to the company prefix, then "foto". */
    public static function base(?string $batchName, ?string $companyPrefix): string
    {
        foreach ([$batchName, $companyPrefix, 'foto'] as $candidate) {
            $slug = Str::of((string) $candidate)->ascii()->slug('-')->limit(self::MAX_BASE_LENGTH, '')->trim('-')->toString();

            if ($slug !== '') {
                return $slug;
            }
        }

        return 'foto';
    }

    /** bmw-320i-01.jpg; two digits up to 99, more digits beyond that. */
    public static function numbered(string $base, int $position, string $extension): string
    {
        return sprintf('%s-%s.%s', $base, str_pad((string) $position, 2, '0', STR_PAD_LEFT), $extension);
    }
}
