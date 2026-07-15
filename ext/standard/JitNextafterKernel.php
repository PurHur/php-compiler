<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_nextafter_kernel() — thin libc nextafter(3) (#19259).
 *
 * Used inside NextafterJitHelper / MathNextafter user-script kernels so nested
 * helper units do not recurse through the nextafter() builtin bridge.
 * php-src: ext/standard/math.c — PHP_FUNCTION(nextafter)
 */
final class JitNextafterKernel
{
    /** @return Value double — nextafter(num, toward) */
    public static function invoke(Context $context, Value $num, Value $toward): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('nextafter'),
            $num,
            $toward
        );
    }
}
