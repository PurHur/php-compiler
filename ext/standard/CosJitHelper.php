<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * cos() for compiled JIT/AOT modules (#15087, #27005, php-in-PHP).
 *
 * Kernel path: {@see phpc_cos_kernel}; VM SSOT remains VmMath::cos.
 * Calling VmMath::cos / \cos from this helper re-enters the MathCos bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27005 — ceil/hypot/sqrt peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(cos)
 */
final class CosJitHelper
{
    public static function cosArgv(float $num): float
    {
        return \phpc_cos_kernel($num);
    }
}
