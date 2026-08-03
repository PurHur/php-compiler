<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * acos() for compiled JIT/AOT modules (#15141, #27048, php-in-PHP).
 *
 * Kernel path: {@see phpc_acos_kernel}; VM SSOT remains VmMath::acos.
 * Calling VmMath::acos / \acos from this helper re-enters the MathAcos bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27048 — cos/ceil/hypot peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(acos)
 */
final class AcosJitHelper
{
    public static function acosArgv(float $num): float
    {
        return \phpc_acos_kernel($num);
    }
}
