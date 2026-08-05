<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_log1p_kernel() — thin libc log1p(3) (#27057).
 *
 * Used inside Log1pJitHelper so NestedJIT helper units do not recurse through log1p()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #27047 exp peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(log1p)
 */
final class JitLog1pKernel
{
    /** @return Value double — log1p(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('log1p'),
            $num
        );
    }
}
