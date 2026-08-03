<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sin() for compiled JIT/AOT modules (#15086, #27048, php-in-PHP).
 *
 * Kernel path: {@see phpc_sin_kernel}; VM SSOT remains VmMath::sin.
 * Calling VmMath::sin / \sin from this helper re-enters the MathSin bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27048 — cos/ceil/hypot peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(sin)
 */
final class SinJitHelper
{
    public static function sinArgv(float $num): float
    {
        return \phpc_sin_kernel($num);
    }
}
