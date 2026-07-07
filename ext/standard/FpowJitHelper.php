<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * fpow() / float pow() for compiled JIT/AOT modules (#15189, php-in-PHP).
 *
 * SSOT: {@see VmMath::fpow()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(fpow), pow_function
 */
final class FpowJitHelper
{
    public static function fpowArgv(float $num, float $exponent): float
    {
        // Leaf for JIT/AOT: pow() lowers to libc when NestedJitCompileScope is active (#17279).
        return \pow($num, $exponent);
    }
}
