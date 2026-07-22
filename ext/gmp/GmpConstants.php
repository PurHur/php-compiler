<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gmp;

/**
 * GMP endian/order constants (php-src ext/gmp/gmp.c; #22337).
 */
final class GmpConstants
{
    public const GMP_MSW_FIRST = 1;
    public const GMP_LSW_FIRST = 2;
    public const GMP_LITTLE_ENDIAN = 4;
    public const GMP_BIG_ENDIAN = 8;
    public const GMP_NATIVE_ENDIAN = 16;

    /**
     * @return array<string, int>
     */
    public static function registeredConstants(): array
    {
        return [
            'GMP_MSW_FIRST' => self::GMP_MSW_FIRST,
            'GMP_LSW_FIRST' => self::GMP_LSW_FIRST,
            'GMP_LITTLE_ENDIAN' => self::GMP_LITTLE_ENDIAN,
            'GMP_BIG_ENDIAN' => self::GMP_BIG_ENDIAN,
            'GMP_NATIVE_ENDIAN' => self::GMP_NATIVE_ENDIAN,
        ];
    }
}
