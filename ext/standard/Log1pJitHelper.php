<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * log1p() for compiled JIT/AOT modules (#15157, #27057, php-in-PHP).
 *
 * Kernel path: {@see phpc_log1p_kernel}; VM SSOT remains VmMath::log1p.
 * Calling VmMath::log1p / \log1p from this helper re-enters the MathLog1p bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27057 — exp #27047 peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(log1p)
 */
final class Log1pJitHelper
{
    public static function log1pArgv(float $num): float
    {
        return \phpc_log1p_kernel($num);
    }
}
