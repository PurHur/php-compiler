<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_tan_kernel() — thin libc tan(3) (#27048).
 *
 * Used inside TanJitHelper so NestedJIT helper units do not recurse through tan()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #20664 hypot/sqrt peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(tan)
 */
final class JitTanKernel
{
    /** @return Value double — tan(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('tan'),
            $num
        );
    }
}
