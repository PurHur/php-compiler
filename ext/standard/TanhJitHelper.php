<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tanh() for compiled JIT/AOT modules (#15156, #27126, php-in-PHP).
 *
 * Kernel path: {@see phpc_tanh_kernel}; VM SSOT remains VmMath::tanh.
 * Calling VmMath::tanh / \tanh from this helper re-enters the MathTanh bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27126 — cosh #27005 peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(tanh)
 */
final class TanhJitHelper
{
    public static function tanhArgv(float $num): float
    {
        return \phpc_tanh_kernel($num);
    }
}
