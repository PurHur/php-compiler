<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_log_kernel() — thin libc log(3) (#27047).
 *
 * Used inside LogJitHelper so NestedJIT helper units do not recurse through log()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #20664 hypot/sqrt peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(log)
 */
final class JitLogKernel
{
    /** @return Value double — log(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('log'),
            $num
        );
    }
}
