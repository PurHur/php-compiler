<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP IEEE754 float encode/decode for pack()/unpack() (self-host; no host \\pack('f'/'d')).
 *
 * php-src: ext/standard/pack.c — php_pack_float / php_unpack_float
 *
 * NestedJIT note (#22990): do **not** return floats via array destructuring
 * (`[$mantissa, $exp] = …`). Array-dim floats are typed as NATIVE_LONG while the
 * LLVM value stays `double`, so Helper emits integer `mul`/`sub` on doubles and
 * module verify fails. Do **not** use by-ref int params (NestedJIT segfault) or
 * bool endian args (ternary always takes the false branch under NestedJIT).
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
        return $littleEndian
            ? self::encodeFloat32Le($value)
            : self::u32ToBytesBe(self::float32ToBits($value));
    }

    public static function encodeFloat64(float $value, bool $littleEndian): string
    {
        return $littleEndian
            ? self::encodeFloat64Le($value)
            : self::u64ToBytesBe(self::float64ToBits($value));
    }

    /** NestedJIT-safe LE float32 (#22990). */
    public static function encodeFloat32Le(float $value): string
    {
        return self::u32ToBytesLe(self::float32ToBits($value));
    }

    /** NestedJIT-safe LE float64 (#22990). */
    public static function encodeFloat64Le(float $value): string
    {
        return self::u64ToBytesLe(self::float64ToBits($value));
    }

    public static function decodeFloat32(string $bytes, bool $littleEndian): float
    {
        return self::bitsToFloat32(
            $littleEndian ? self::bytesToU32Le($bytes) : self::bytesToU32Be($bytes)
        );
    }

    public static function decodeFloat64(string $bytes, bool $littleEndian): float
    {
        return self::bitsToFloat64(
            $littleEndian ? self::bytesToU64Le($bytes) : self::bytesToU64Be($bytes)
        );
    }

    /** NestedJIT-safe LE float64 decode (#22990). */
    public static function decodeFloat64Le(string $bytes): float
    {
        return self::bitsToFloat64(self::bytesToU64Le($bytes));
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
        $abs = \abs($value);
        // Inline frexp — NestedJIT by-ref int segfaults (#22990).
        $exp = 0;
        $mantissa = $abs;
        while ($mantissa >= 1.0) {
            $mantissa = $mantissa / 2.0;
            ++$exp;
        }
        while ($mantissa > 0.0 && $mantissa < 0.5) {
            $mantissa = $mantissa * 2.0;
            --$exp;
        }
        $exp = $exp - 1 + 127;
        // Keep every intermediate as float — NestedJIT (#22990).
        $scaled = ($mantissa - 0.5) * 2.0 * 8388608.0;
        $fraction = (int) \round($scaled);
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
        $fracF = (float) $frac;

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
            $value = ($fracF / 8388608.0) * self::pow2(-126);

            return $sign ? -$value : $value;
        }
        $value = (1.0 + $fracF / 8388608.0) * self::pow2($exp - 127);

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
        $abs = \abs($value);
        // Inline frexp — NestedJIT by-ref int segfaults (#22990).
        $exp = 0;
        $mantissa = $abs;
        while ($mantissa >= 1.0) {
            $mantissa = $mantissa / 2.0;
            ++$exp;
        }
        while ($mantissa > 0.0 && $mantissa < 0.5) {
            $mantissa = $mantissa * 2.0;
            --$exp;
        }
        $exp = $exp - 1 + 1023;
        $scaled = ($mantissa - 0.5) * 2.0 * 4503599627370496.0;
        $fraction = (int) \round($scaled);
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
        $hi = $limbs[0];
        $lo = $limbs[1];
        $sign = ($hi >> 31) & 1;
        $exp = ($hi >> 20) & 0x7FF;
        $fracHi = $hi & 0xFFFFF;
        $frac = ((float) $fracHi) * 4294967296.0 + (float) ($lo & 0xFFFFFFFF);

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
            $value = ($frac / 4503599627370496.0) * self::pow2(-1022);

            return $sign ? -$value : $value;
        }
        $value = (1.0 + $frac / 4503599627370496.0) * self::pow2($exp - 1023);

        return $sign ? -$value : $value;
    }

    private static function u32ToBytesLe(int $bits): string
    {
        return \chr($bits & 0xFF)
            .\chr(($bits >> 8) & 0xFF)
            .\chr(($bits >> 16) & 0xFF)
            .\chr(($bits >> 24) & 0xFF);
    }

    private static function u32ToBytesBe(int $bits): string
    {
        return \chr(($bits >> 24) & 0xFF)
            .\chr(($bits >> 16) & 0xFF)
            .\chr(($bits >> 8) & 0xFF)
            .\chr($bits & 0xFF);
    }

    private static function bytesToU32Le(string $bytes): int
    {
        return \ord($bytes[0])
            | (\ord($bytes[1]) << 8)
            | (\ord($bytes[2]) << 16)
            | (\ord($bytes[3]) << 24);
    }

    private static function bytesToU32Be(string $bytes): int
    {
        return \ord($bytes[3])
            | (\ord($bytes[2]) << 8)
            | (\ord($bytes[1]) << 16)
            | (\ord($bytes[0]) << 24);
    }

    /** @param array{0: int, 1: int} $limbs */
    private static function u64ToBytesLe(array $limbs): string
    {
        return self::u32ToBytesLe($limbs[1]).self::u32ToBytesLe($limbs[0]);
    }

    /** @param array{0: int, 1: int} $limbs */
    private static function u64ToBytesBe(array $limbs): string
    {
        return self::u32ToBytesBe($limbs[0]).self::u32ToBytesBe($limbs[1]);
    }

    /** @return array{0: int, 1: int} */
    private static function bytesToU64Le(string $bytes): array
    {
        return [
            self::bytesToU32Le(\substr($bytes, 4, 4)),
            self::bytesToU32Le(\substr($bytes, 0, 4)),
        ];
    }

    /** @return array{0: int, 1: int} */
    private static function bytesToU64Be(string $bytes): array
    {
        return [
            self::bytesToU32Be(\substr($bytes, 0, 4)),
            self::bytesToU32Be(\substr($bytes, 4, 4)),
        ];
    }

    /** Integer power-of-two for nested JIT (avoids pow() lowering). */
    private static function pow2(int $exp): float
    {
        if (0 === $exp) {
            return 1.0;
        }
        $value = 1.0;
        if ($exp > 0) {
            for ($i = 0; $i < $exp; ++$i) {
                $value = $value * 2.0;
            }

            return $value;
        }
        for ($i = 0; $i > $exp; --$i) {
            $value = $value / 2.0;
        }

        return $value;
    }
}
