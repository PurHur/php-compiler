<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * fmod() for compiled JIT/AOT modules (#15072, #26994, php-in-PHP).
 *
 * Kernel path: {@see phpc_fmod_kernel}; VM SSOT remains VmMath::fmod.
 * Calling VmMath::fmod / \fmod from this helper re-enters the MathFmod bridge under
 * NestedJIT and yields 0 under thin standalone AOT (#26994 — hypot peer #20664).
 * php-src: ext/standard/math.c — PHP_FUNCTION(fmod)
 */
final class FmodJitHelper
{
    public static function fmodArgv(float $num1, float $num2): float
    {
        return \phpc_fmod_kernel($num1, $num2);
    }
}
