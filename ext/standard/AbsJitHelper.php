<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * abs() for compiled JIT/AOT modules (#15175, php-in-PHP).
 *
 * SSOT mirrors {@see abs::execute()} int/float paths.
 * php-src: ext/standard/math.c — PHP_FUNCTION(abs) / fabs (clears sign bit)
 */
final class AbsJitHelper
{
    public static function absDoubleArgv(float $num): float
    {
        // php-src fabs: abs(-0.0) → +0.0. PHP `$num < 0.0` is false for -0.0 (#23978).
        if ($num < 0.0) {
            return -$num;
        }
        if (0.0 === $num) {
            return 0.0;
        }

        return $num;
    }

    public static function absLongArgv(int $num): int
    {
        return $num < 0 ? -$num : $num;
    }
}
