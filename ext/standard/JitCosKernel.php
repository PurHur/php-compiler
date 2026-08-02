<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_cos_kernel() — thin libc cos(3) (#27005).
 *
 * Used inside CosJitHelper so NestedJIT helper units do not recurse through cos()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #20664 hypot/sqrt peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(cos)
 */
final class JitCosKernel
{
    /** @return Value double — cos(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('cos'),
            $num
        );
    }
}
