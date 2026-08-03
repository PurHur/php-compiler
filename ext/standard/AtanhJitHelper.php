<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * atanh() for compiled JIT/AOT modules (#15221, #27058, php-in-PHP).
 *
 * Kernel path: {@see phpc_atanh_kernel}; VM SSOT remains VmMath::atanh.
 * Calling VmMath::atanh / \atanh from this helper re-enters the MathAtanh bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27058 — sinh #27125 peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(atanh)
 */
final class AtanhJitHelper
{
    public static function atanhArgv(float $num): float
    {
        return \phpc_atanh_kernel($num);
    }
}
