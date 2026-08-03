<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_sinh_kernel() — thin libc sinh(3) (#27125).
 *
 * Used inside SinhJitHelper so NestedJIT helper units do not recurse through sinh()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #27005 cosh peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(sinh)
 */
final class JitSinhKernel
{
    /** @return Value double — sinh(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('sinh'),
            $num
        );
    }
}
