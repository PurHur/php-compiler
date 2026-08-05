<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_expm1_kernel() — thin libc expm1(3) (#27057).
 *
 * Used inside Expm1JitHelper so NestedJIT helper units do not recurse through expm1()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #27047 exp peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(expm1)
 */
final class JitExpm1Kernel
{
    /** @return Value double — expm1(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('expm1'),
            $num
        );
    }
}
