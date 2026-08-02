<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_floor_kernel() — thin libc floor(3) (#27004).
 *
 * Used inside FloorJitHelper so NestedJIT helper units do not recurse through floor()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #20664 hypot/sqrt peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(floor)
 */
final class JitFloorKernel
{
    /** @return Value double — floor(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('floor'),
            $num
        );
    }
}
