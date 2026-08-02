<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * cosh() for compiled JIT/AOT modules (#15156, #27005, php-in-PHP).
 *
 * Kernel path: {@see phpc_cosh_kernel}; VM SSOT remains VmMath::cosh.
 * Calling VmMath::cosh / \cosh from this helper re-enters the MathCosh bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27005 — ceil/hypot/sqrt peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(cosh)
 */
final class CoshJitHelper
{
    public static function coshArgv(float $num): float
    {
        return \phpc_cosh_kernel($num);
    }
}
