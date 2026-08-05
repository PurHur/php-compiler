<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_atan_kernel() — thin libc atan(3) (#27017).
 *
 * Used inside AtanJitHelper so NestedJIT helper units do not recurse through atan()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #27016 asin peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(atan)
 */
final class JitAtanKernel
{
    /** @return Value double — atan(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('atan'),
            $num
        );
    }
}
