<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * exp() for compiled JIT/AOT modules (#15116, #27047, php-in-PHP).
 *
 * Kernel path: {@see phpc_exp_kernel}; VM SSOT remains VmMath::exp.
 * Calling VmMath::exp / \exp from this helper re-enters the MathExp bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27047 — cos/ceil/hypot peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(exp)
 */
final class ExpJitHelper
{
    public static function expArgv(float $num): float
    {
        return \phpc_exp_kernel($num);
    }
}
