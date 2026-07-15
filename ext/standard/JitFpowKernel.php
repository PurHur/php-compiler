<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_fpow_kernel() — thin libc pow(3) (#19259).
 *
 * Used inside FpowJitHelper / MathFpow user-script kernels so nested helper
 * units do not recurse through the fpow()/pow() builtin bridge.
 * php-src: ext/standard/math.c — PHP_FUNCTION(fpow)
 */
final class JitFpowKernel
{
    /** @return Value double — pow(num, exponent) */
    public static function invoke(Context $context, Value $num, Value $exponent): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('pow'),
            $num,
            $exponent
        );
    }
}
