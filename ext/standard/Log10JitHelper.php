<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * log10() for compiled JIT/AOT modules (#15101, #27047, php-in-PHP).
 *
 * Kernel path: {@see phpc_log10_kernel}; VM SSOT remains VmMath::log10.
 * Calling VmMath::log10 / \log10 from this helper re-enters the MathLog10 bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27047 — cos/ceil/hypot peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(log10)
 */
final class Log10JitHelper
{
    public static function log10Argv(float $num): float
    {
        return \phpc_log10_kernel($num);
    }
}
