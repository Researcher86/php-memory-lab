<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * Renders byte counts in short, uniform units. Every experiment prints through
 * this so the numbers stay comparable between runs instead of each script
 * inventing its own formatting.
 */
final class ByteFormatter
{
    public static function format(int $bytes, int $precision = 2): string
    {
        if ($bytes < 0) {
            return '-' . self::format(-$bytes, $precision);
        }

        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            ++$index;
        }

        $unit = $units[$index];

        $digits = $unit === 'B' ? 0 : $precision;

        return number_format($value, $digits, '.', '') . ' ' . $unit;
    }

    /**
     * Same as format(), but with an explicit sign so a delta reads as a
     * change ("+32.1 MiB") rather than a bare magnitude.
     */
    public static function formatSigned(int $bytes, int $precision = 2): string
    {
        return ($bytes > 0 ? '+' : '') . self::format($bytes, $precision);
    }
}
