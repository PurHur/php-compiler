<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * atan() for compiled JIT/AOT modules (#15142, #27017, php-in-PHP).
 *
 * Kernel path: {@see phpc_atan_kernel}; VM SSOT remains VmMath::atan.
 * Calling VmMath::atan / \atan from this helper re-enters the MathAtan bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27017 — asin #27016 peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(atan)
 */
final class AtanJitHelper
{
    public static function atanArgv(float $num): float
    {
        return \phpc_atan_kernel($num);
    }
}
