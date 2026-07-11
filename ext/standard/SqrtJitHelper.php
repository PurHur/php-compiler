<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sqrt() for compiled JIT/AOT modules (#15115, php-in-PHP).
 *
 * SSOT: {@see VmMath::sqrt()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(sqrt)
 */
final class SqrtJitHelper
{
    public static function sqrtArgv(float $num): float
    {
        return VmMath::sqrt($num);
    }
}
