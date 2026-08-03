<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_asinh_kernel() — thin libc asinh(3) (#27058).
 *
 * Used inside AsinhJitHelper so NestedJIT helper units do not recurse through asinh()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #27125 sinh peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(asinh)
 */
final class JitAsinhKernel
{
    /** @return Value double — asinh(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('asinh'),
            $num
        );
    }
}
