<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * abs() for compiled JIT/AOT modules (#15175, php-in-PHP).
 *
 * SSOT mirrors {@see abs::execute()} int/float paths.
 * php-src: ext/standard/math.c — PHP_FUNCTION(abs)
 */
final class AbsJitHelper
{
    public static function absDoubleArgv(float $num): float
    {
        return $num < 0.0 ? -$num : $num;
    }

    public static function absLongArgv(int $num): int
    {
        return $num < 0 ? -$num : $num;
    }
}
