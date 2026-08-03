<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * acosh() for compiled JIT/AOT modules (#15221, #27058, php-in-PHP).
 *
 * Kernel path: {@see phpc_acosh_kernel}; VM SSOT remains VmMath::acosh.
 * Calling VmMath::acosh / \acosh from this helper re-enters the MathAcosh bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#27058 — sinh #27125 peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(acosh)
 */
final class AcoshJitHelper
{
    public static function acoshArgv(float $num): float
    {
        return \phpc_acosh_kernel($num);
    }
}
