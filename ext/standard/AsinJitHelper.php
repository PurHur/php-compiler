<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * asin() for compiled JIT/AOT modules (#15130, #27016, php-in-PHP).
 *
 * Kernel path: {@see phpc_asin_kernel}; VM SSOT remains VmMath::asin.
 * Calling VmMath::asin / \asin from this helper re-enters the MathAsin bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27016 — acos/cos/ceil peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(asin)
 */
final class AsinJitHelper
{
    public static function asinArgv(float $num): float
    {
        return \phpc_asin_kernel($num);
    }
}
