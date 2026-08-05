<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * atan2() for compiled JIT/AOT modules (#15102, #27017, php-in-PHP).
 *
 * Kernel path: {@see phpc_atan2_kernel}; VM SSOT remains VmMath::atan2.
 * Calling VmMath::atan2 / \atan2 from this helper re-enters the MathAtan2 bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27017 — asin #27016 peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(atan2)
 */
final class Atan2JitHelper
{
    public static function atan2Argv(float $y, float $x): float
    {
        return \phpc_atan2_kernel($y, $x);
    }
}
