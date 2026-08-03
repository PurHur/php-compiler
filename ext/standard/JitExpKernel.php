<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_exp_kernel() — thin libc exp(3) (#27047).
 *
 * Used inside ExpJitHelper so NestedJIT helper units do not recurse through exp()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #20664 hypot/sqrt peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(exp)
 */
final class JitExpKernel
{
    /** @return Value double — exp(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('exp'),
            $num
        );
    }
}
