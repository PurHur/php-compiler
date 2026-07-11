<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sin() for compiled JIT/AOT modules (#15086, php-in-PHP).
 *
 * SSOT: {@see VmMath::sin()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(sin)
 */
final class SinJitHelper
{
    public static function sinArgv(float $num): float
    {
        return VmMath::sin($num);
    }
}
