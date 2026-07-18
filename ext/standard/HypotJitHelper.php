<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hypot() for compiled JIT/AOT modules (#15074, #20664, php-in-PHP).
 *
 * Kernel path: {@see phpc_hypot_kernel}; VM SSOT remains VmMath::hypot.
 * php-src: ext/standard/math.c — PHP_FUNCTION(hypot)
 */
final class HypotJitHelper
{
    public static function hypotArgv(float $x, float $y): float
    {
        return \phpc_hypot_kernel($x, $y);
    }
}
