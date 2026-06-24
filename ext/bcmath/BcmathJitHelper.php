<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bcmath;

/**
 * bcmath __compiler_bc* ABI for compiled JIT/AOT modules (#9235, php-in-PHP).
 *
 * SSOT: {@see VmBcmath}
 * php-src: ext/bcmath/libbcmath/src/*, ext/bcmath/bcmath.c
 */
final class BcmathJitHelper
{
    public static function bcscaleAsInt(int $scale, int $hasScale): int
    {
        if (-1 === $hasScale) {
            return VmBcmath::scale();
        }

        return VmBcmath::scale($scale);
    }

    public static function add(string $left, string $right, int $scale, int $hasScale): string
    {
        return VmBcmath::add($left, $right, self::resolveScale($scale, $hasScale));
    }

    public static function sub(string $left, string $right, int $scale, int $hasScale): string
    {
        return VmBcmath::sub($left, $right, self::resolveScale($scale, $hasScale));
    }

    public static function mul(string $left, string $right, int $scale, int $hasScale): string
    {
        return VmBcmath::mul($left, $right, self::resolveScale($scale, $hasScale));
    }

    public static function div(string $left, string $right, int $scale, int $hasScale): string
    {
        return VmBcmath::div($left, $right, self::resolveScale($scale, $hasScale));
    }

    public static function comp(string $left, string $right, int $scale, int $hasScale): int
    {
        return VmBcmath::comp($left, $right, self::resolveScale($scale, $hasScale));
    }

    public static function powmod(
        string $base,
        string $exponent,
        string $modulus,
        int $scale,
        int $hasScale
    ): string {
        return VmBcmath::powmod($base, $exponent, $modulus, self::resolveScale($scale, $hasScale));
    }

    public static function round(string $num, int $precision, int $mode): string
    {
        return VmBcmath::round($num, $precision, $mode);
    }

    private static function resolveScale(int $scale, int $hasScale): ?int
    {
        return -1 === $hasScale ? null : $scale;
    }
}
