<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_cosh_kernel() — thin libc cosh(3) (#27005).
 *
 * Used inside CoshJitHelper so NestedJIT helper units do not recurse through cosh()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #20664 hypot/sqrt peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(cosh)
 */
final class JitCoshKernel
{
    /** @return Value double — cosh(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('cosh'),
            $num
        );
    }
}
