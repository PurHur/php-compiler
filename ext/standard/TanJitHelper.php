<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tan() for compiled JIT/AOT modules (#15088, #27048, php-in-PHP).
 *
 * Kernel path: {@see phpc_tan_kernel}; VM SSOT remains VmMath::tan.
 * Calling VmMath::tan / \tan from this helper re-enters the MathTan bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27048 — cos/ceil/hypot peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(tan)
 */
final class TanJitHelper
{
    public static function tanArgv(float $num): float
    {
        return \phpc_tan_kernel($num);
    }
}
