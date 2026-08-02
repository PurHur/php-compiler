<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_ceil_kernel() — thin libc ceil(3) (#27003).
 *
 * Used inside CeilJitHelper so NestedJIT helper units do not recurse through ceil()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #20664 hypot/sqrt peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(ceil)
 */
final class JitCeilKernel
{
    /** @return Value double — ceil(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('ceil'),
            $num
        );
    }
}
