<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_hypot_kernel() — thin libc hypot(3) (#15074, #20664).
 *
 * Used inside HypotJitHelper so NestedJIT helper units do not recurse through hypot()
 * or stub VmMath under pre-registerModule NestedJIT (#15417).
 * php-src: ext/standard/math.c — PHP_FUNCTION(hypot)
 */
final class JitHypotKernel
{
    /** @return Value double — hypot(x, y) */
    public static function invoke(Context $context, Value $x, Value $y): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('hypot'),
            $x,
            $y
        );
    }
}
