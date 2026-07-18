<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_sqrt_kernel() — thin libc sqrt(3) (#15115, #20664).
 *
 * Used inside SqrtJitHelper so NestedJIT helper units do not recurse through sqrt()
 * or stub VmMath under pre-registerModule NestedJIT (#15417).
 * php-src: ext/standard/math.c — PHP_FUNCTION(sqrt)
 */
final class JitSqrtKernel
{
    /** @return Value double — sqrt(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('sqrt'),
            $num
        );
    }
}
