<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sinh() for compiled JIT/AOT modules (#15156, #27125, php-in-PHP).
 *
 * Kernel path: {@see phpc_sinh_kernel}; VM SSOT remains VmMath::sinh.
 * Calling VmMath::sinh / \sinh from this helper re-enters the MathSinh bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27125 — cosh #27005 peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(sinh)
 */
final class SinhJitHelper
{
    public static function sinhArgv(float $num): float
    {
        return \phpc_sinh_kernel($num);
    }
}
