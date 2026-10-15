<?php

namespace BB\ContentPull;

use InvalidArgumentException;

/**
 * Human-readable byte sizes ("20M", "512K", "1.5G").
 */
final class Size
{
    private const UNITS = ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3];

    public static function parse(string|int|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) === true) {
            return $value;
        }

        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMG]?)(?:i?B)?\s*$/i', $value, $match) !== 1) {
            throw new InvalidArgumentException('Invalid size "' . $value . '" (expected e.g. 500K, 20M, 1G)');
        }

        return (int) round((float) $match[1] * self::UNITS[strtoupper($match[2])]);
    }

    public static function format(int $bytes): string
    {
        foreach (['G', 'M', 'K'] as $unit) {
            if ($bytes >= self::UNITS[$unit]) {
                return round($bytes / self::UNITS[$unit], 1) . ' ' . $unit . 'B';
            }
        }

        return $bytes . ' B';
    }
}
