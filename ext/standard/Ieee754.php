<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP IEEE754 float encode/decode for pack()/unpack() (self-host; no host \\pack('f'/'d')).
 *
 * php-src: ext/standard/pack.c — php_pack_float / php_unpack_float
 */
final class Ieee754
{
    /** php-src — IEEE754 negative zero (sign bit set, magnitude zero). */
    public static function isNegativeZero(float $value): bool
    {
        return 0.0 == $value && 0.0 !== \atan2(0.0, $value);
    }

    public static function encodeFloat32(float $value, bool $littleEndian): string
    {
        return self::u32ToBytes(self::float32ToBits($value), $littleEndian);
    }

    public static function encodeFloat64(float $value, bool $littleEndian): string
    {
        return self::u64ToBytes(self::float64ToBits($value), $littleEndian);
    }

    public static function decodeFloat32(string $bytes, bool $littleEndian): float
    {
        return self::bitsToFloat32(self::bytesToU32($bytes, $littleEndian));
    }

    public static function decodeFloat64(string $bytes, bool $littleEndian): float
    {
        return self::bitsToFloat64(self::bytesToU64($bytes, $littleEndian));
    }

    public static function float32ToBits(float $value): int
    {
        if (is_nan($value)) {
            return 0x7FC00000;
        }
        if ($value === INF) {
            return 0x7F800000;
        }
        if ($value === -INF) {
            return 0xFF800000;
        }
        if (0.0 == $value) {
            return 0.0 !== \atan2(0.0, $value) ? 0x80000000 : 0;
        }

        $sign = $value < 0 ? 0x80000000 : 0;
        $abs = abs($value);
        [$mantissa, $exponent] = self::frexpDecompose($abs);
        $exp = $exponent - 1 + 127;
        $fraction = (int) round(($mantissa - 0.5) * 2.0 * 8388608.0);
        if ($fraction >= 8388608) {
            $fraction = 0;
            ++$exp;
        }

        return $sign | (($exp & 0xFF) << 23) | ($fraction & 0x7FFFFF);
    }

    public static function bitsToFloat32(int $bits): float
    {
        $sign = ($bits >> 31) & 1;
        $exp = ($bits >> 23) & 0xFF;
        $frac = $bits & 0x7FFFFF;

        if (0xFF === $exp) {
            if (0 === $frac) {
                return $sign ? -INF : INF;
            }

            return NAN;
        }
        if (0 === $exp) {
            if (0 === $frac) {
                return $sign ? -0.0 : 0.0;
            }
            $value = ($frac / 8388608.0) * 2.0 ** -126;

            return $sign ? -$value : $value;
        }
        $value = (1.0 + $frac / 8388608.0) * 2.0 ** ($exp - 127);

        return $sign ? -$value : $value;
    }

    /** @return array{0: int, 1: int} high, low unsigned 32-bit limbs */
    public static function float64ToBits(float $value): array
    {
        if (is_nan($value)) {
            return [0x7FF80000, 0x00000000];
        }
        if ($value === INF) {
            return [0x7FF00000, 0x00000000];
        }
        if ($value === -INF) {
            return [0xFFF00000, 0x00000000];
        }
        if (0.0 == $value) {
            return 0.0 !== \atan2(0.0, $value)
                ? [0x80000000, 0x00000000]
                : [0x00000000, 0x00000000];
        }

        $sign = $value < 0 ? 1 : 0;
        $abs = abs($value);
        [$mantissa, $exponent] = self::frexpDecompose($abs);
        $exp = $exponent - 1 + 1023;
        $fraction = (int) round(($mantissa - 0.5) * 2.0 * 4503599627370496.0);
        if ($fraction >= 4503599627370496) {
            $fraction = 0;
            ++$exp;
        }

        $hi = ($sign << 31) | (($exp & 0x7FF) << 20) | (int) (($fraction >> 32) & 0xFFFFF);
        $lo = $fraction & 0xFFFFFFFF;

        return [$hi, $lo];
    }

    /** @param array{0: int, 1: int} $limbs */
    public static function bitsToFloat64(array $limbs): float
    {
        [$hi, $lo] = $limbs;
        $sign = ($hi >> 31) & 1;
        $exp = ($hi >> 20) & 0x7FF;
        $fracHi = $hi & 0xFFFFF;
        $frac = ((float) $fracHi) * 4294967296.0 + ($lo & 0xFFFFFFFF);

        if (0x7FF === $exp) {
            if (0.0 === $frac) {
                return $sign ? -INF : INF;
            }

            return NAN;
        }
        if (0 === $exp) {
            if (0.0 === $frac) {
                return $sign ? -0.0 : 0.0;
            }
            $value = ($frac / 4503599627370496.0) * 2.0 ** -1022;

            return $sign ? -$value : $value;
        }
        $value = (1.0 + $frac / 4503599627370496.0) * 2.0 ** ($exp - 1023);

        return $sign ? -$value : $value;
    }

    private static function u32ToBytes(int $bits, bool $littleEndian): string
    {
        $b0 = \chr($bits & 0xFF);
        $b1 = \chr(($bits >> 8) & 0xFF);
        $b2 = \chr(($bits >> 16) & 0xFF);
        $b3 = \chr(($bits >> 24) & 0xFF);

        return $littleEndian ? $b0.$b1.$b2.$b3 : $b3.$b2.$b1.$b0;
    }

    private static function bytesToU32(string $bytes, bool $littleEndian): int
    {
        if ($littleEndian) {
            return \ord($bytes[0])
                | (\ord($bytes[1]) << 8)
                | (\ord($bytes[2]) << 16)
                | (\ord($bytes[3]) << 24);
        }

        return \ord($bytes[3])
            | (\ord($bytes[2]) << 8)
            | (\ord($bytes[1]) << 16)
            | (\ord($bytes[0]) << 24);
    }

    /** @param array{0: int, 1: int} $limbs */
    private static function u64ToBytes(array $limbs, bool $littleEndian): string
    {
        [$hi, $lo] = $limbs;

        return $littleEndian
            ? self::u32ToBytes($lo, true).self::u32ToBytes($hi, true)
            : self::u32ToBytes($hi, false).self::u32ToBytes($lo, false);
    }

    /** @return array{0: int, 1: int} */
    private static function bytesToU64(string $bytes, bool $littleEndian): array
    {
        if ($littleEndian) {
            return [
                self::bytesToU32(\substr($bytes, 4, 4), true),
                self::bytesToU32(\substr($bytes, 0, 4), true),
            ];
        }

        return [
            self::bytesToU32(\substr($bytes, 0, 4), false),
            self::bytesToU32(\substr($bytes, 4, 4), false),
        ];
    }

    /** @return array{0: float, 1: int} mantissa in [0.5, 1), php-src frexp(3) semantics */
    private static function frexpDecompose(float $abs): array
    {
        if (0.0 === $abs) {
            return [0.0, 0];
        }
        $exp = (int) floor(log($abs, 2.0));
        $mantissa = $abs / (2.0 ** $exp);
        while ($mantissa >= 1.0) {
            $mantissa /= 2.0;
            ++$exp;
        }
        while ($mantissa > 0.0 && $mantissa < 0.5) {
            $mantissa *= 2.0;
            --$exp;
        }

        return [$mantissa, $exp];
    }
}
