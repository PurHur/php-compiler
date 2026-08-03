<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_asin_kernel() — thin libc asin(3) (#27016).
 *
 * Used inside AsinJitHelper so NestedJIT helper units do not recurse through asin()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #27048 acos peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(asin)
 */
final class JitAsinKernel
{
    /** @return Value double — asin(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('asin'),
            $num
        );
    }
}
