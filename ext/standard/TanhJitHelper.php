<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tanh() for compiled JIT/AOT modules (#15156, php-in-PHP).
 *
 * SSOT: {@see VmMath::tanh()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(tanh)
 */
final class TanhJitHelper
{
    public static function tanhArgv(float $num): float
    {
        return VmMath::tanh($num);
    }
}
