<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_sin_kernel() — thin libc sin(3) (#27048).
 *
 * Used inside SinJitHelper so NestedJIT helper units do not recurse through sin()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #20664 hypot/sqrt peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(sin)
 */
final class JitSinKernel
{
    /** @return Value double — sin(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('sin'),
            $num
        );
    }
}
