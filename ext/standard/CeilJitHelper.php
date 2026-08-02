<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ceil() for compiled JIT/AOT modules (#15129, #27003, php-in-PHP).
 *
 * Kernel path: {@see phpc_ceil_kernel}; VM SSOT remains VmMath::ceil.
 * Calling VmMath::ceil / \ceil from this helper re-enters the MathCeil bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27003 — hypot/sqrt peer #20664).
 * php-src: ext/standard/math.c — PHP_FUNCTION(ceil)
 */
final class CeilJitHelper
{
    public static function ceilArgv(float $num): float
    {
        return \phpc_ceil_kernel($num);
    }
}
