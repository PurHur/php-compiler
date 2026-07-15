<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * fpow() / float pow() for compiled JIT/AOT modules (#15189, #19259, php-in-PHP).
 *
 * Kernel path: {@see phpc_fpow_kernel}; VM SSOT remains VmMath::fpow.
 * php-src: ext/standard/math.c — PHP_FUNCTION(fpow), pow_function
 */
final class FpowJitHelper
{
    public static function fpowArgv(float $num, float $exponent): float
    {
        return \phpc_fpow_kernel($num, $exponent);
    }
}
