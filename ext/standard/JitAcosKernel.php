<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_acos_kernel() — thin libc acos(3) (#27048).
 *
 * Used inside AcosJitHelper so NestedJIT helper units do not recurse through acos()
 * or stub VmMath under pre-registerModule NestedJIT (#15417 / #20664 hypot/sqrt peer).
 * php-src: ext/standard/math.c — PHP_FUNCTION(acos)
 */
final class JitAcosKernel
{
    /** @return Value double — acos(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        LibcExtern::register($context);

        return $context->builder->call(
            $context->lookupFunction('acos'),
            $num
        );
    }
}
