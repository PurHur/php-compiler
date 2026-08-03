<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_tanh_kernel() — thin libc tanh(3) (#27126).
 *
 * Used inside TanhJitHelper so NestedJIT helper units do not recurse through tanh()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #27005 cosh peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(tanh)
 */
final class JitTanhKernel
{
    /** @return Value double — tanh(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('tanh'),
            $num
        );
    }
}
