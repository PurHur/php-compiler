<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_fmod_kernel() — thin libc fmod(3) (#26994).
 *
 * Used inside FmodJitHelper so NestedJIT helper units do not recurse through fmod()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #20664 hypot peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(fmod)
 */
final class JitFmodKernel
{
    /** @return Value double — fmod(num1, num2) */
    public static function invoke(Context $context, Value $num1, Value $num2): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('fmod'),
            $num1,
            $num2
        );
    }
}
