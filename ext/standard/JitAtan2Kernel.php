<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_atan2_kernel() — thin libc atan2(3) (#27017).
 *
 * Used inside Atan2JitHelper so NestedJIT helper units do not recurse through atan2()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #27016 asin peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(atan2)
 */
final class JitAtan2Kernel
{
    /** @return Value double — atan2(y, x) */
    public static function invoke(Context $context, Value $y, Value $x): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('atan2'),
            $y,
            $x
        );
    }
}
