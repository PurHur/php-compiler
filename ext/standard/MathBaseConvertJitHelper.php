<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * base_convert() / radix parse for compiled JIT/AOT modules (#9584, php-in-PHP).
 *
 * SSOT: {@see VmMath}
 * php-src: ext/standard/math.c — _php_math_basetozval, _php_math_zvaltobase, PHP_FUNCTION(base_convert)
 */
final class MathBaseConvertJitHelper
{
    private static int $lastLong = 0;

    private static float $lastDouble = 0.0;

    public static function baseConvert(string $number, int $fromBase, int $toBase): string
    {
        return VmMath::baseConvert($number, $fromBase, $toBase);
    }

    /** @return int 0=long result, 1=double result (LLVM i32 ABI) */
    public static function parseBaseToZval(string $str, int $base): int
    {
        $value = VmMath::baseToZval($str, $base);
        if (\is_float($value)) {
            self::$lastDouble = $value;

            return 1;
        }
        self::$lastLong = (int) $value;

        return 0;
    }

    public static function lastLong(): int
    {
        return self::$lastLong;
    }

    public static function lastDouble(): float
    {
        return self::$lastDouble;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$lastLong = 0;
        self::$lastDouble = 0.0;
    }
}
