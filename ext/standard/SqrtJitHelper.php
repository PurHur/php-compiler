<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sqrt() for compiled JIT/AOT modules (#15115, #20664, php-in-PHP).
 *
 * Kernel path: {@see phpc_sqrt_kernel}; VM SSOT remains VmMath::sqrt.
 * php-src: ext/standard/math.c — PHP_FUNCTION(sqrt)
 */
final class SqrtJitHelper
{
    public static function sqrtArgv(float $num): float
    {
        return \phpc_sqrt_kernel($num);
    }
}
