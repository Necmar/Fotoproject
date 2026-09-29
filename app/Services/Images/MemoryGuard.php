<?php

namespace App\Services\Images;

use App\Exceptions\DomainRuleException;

/**
 * GD needs about 4 bytes per pixel (plus working copies). Running out of
 * memory is a fatal error that cannot be caught, so check up front and try
 * to raise the limit where the host allows it.
 */
class MemoryGuard
{
    /** Bytes per pixel including the resampled copy and GD overhead. */
    private const BYTES_PER_PIXEL = 6;

    public static function ensureFor(int $width, int $height): void
    {
        $needed = (int) ($width * $height * self::BYTES_PER_PIXEL) + memory_get_usage(true) + 32 * 1024 * 1024;
        $limit = self::limit();

        if ($limit === -1 || $needed <= $limit) {
            return;
        }

        $target = (string) config('bora.processing.memory_limit', '1024M');
        @ini_set('memory_limit', $target);

        $limit = self::limit();
        if ($limit !== -1 && $needed > $limit) {
            throw new DomainRuleException('too_many_pixels');
        }
    }

    public static function limit(): int
    {
        return self::toBytes((string) ini_get('memory_limit'));
    }

    public static function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
