<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_atanh_kernel() — thin libc atanh(3) (#27058).
 *
 * Used inside AtanhJitHelper so NestedJIT helper units do not recurse through atanh()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #27125 sinh peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(atanh)
 */
final class JitAtanhKernel
{
    /** @return Value double — atanh(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('atanh'),
            $num
        );
    }
}
