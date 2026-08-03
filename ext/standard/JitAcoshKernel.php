<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_acosh_kernel() — thin libc acosh(3) (#27058).
 *
 * Used inside AcoshJitHelper so NestedJIT helper units do not recurse through acosh()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #27125 sinh peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(acosh)
 */
final class JitAcoshKernel
{
    /** @return Value double — acosh(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('acosh'),
            $num
        );
    }
}
