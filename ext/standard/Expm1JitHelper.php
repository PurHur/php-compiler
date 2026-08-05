<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * expm1() for compiled JIT/AOT modules (#15157, #27057, php-in-PHP).
 *
 * Kernel path: {@see phpc_expm1_kernel}; VM SSOT remains VmMath::expm1.
 * Calling VmMath::expm1 / \expm1 from this helper re-enters the MathExpm1 bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27057 — exp #27047 peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(expm1)
 */
final class Expm1JitHelper
{
    public static function expm1Argv(float $num): float
    {
        return \phpc_expm1_kernel($num);
    }
}
