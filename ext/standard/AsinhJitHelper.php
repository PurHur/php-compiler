<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * asinh() for compiled JIT/AOT modules (#15221, #27058, php-in-PHP).
 *
 * Kernel path: {@see phpc_asinh_kernel}; VM SSOT remains VmMath::asinh.
 * Calling VmMath::asinh / \asinh from this helper re-enters the MathAsinh bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27058 — sinh #27125 peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(asinh)
 */
final class AsinhJitHelper
{
    public static function asinhArgv(float $num): float
    {
        return \phpc_asinh_kernel($num);
    }
}
