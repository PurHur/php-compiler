<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_log10_kernel() — thin libc log10(3) (#27047).
 *
 * Used inside Log10JitHelper so NestedJIT helper units do not recurse through log10()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #20664 hypot/sqrt peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(log10)
 */
final class JitLog10Kernel
{
    /** @return Value double — log10(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('log10'),
            $num
        );
    }
}
