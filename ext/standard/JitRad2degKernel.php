<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM lowering for rad2deg leaf — fmul by (180/M_PI) (#26996 / #27006).
 *
 * Used inside Rad2degJitHelper / NestedJIT so helpers do not call VmMath
 * (NestedJIT stubs cross-class calls to null→0 under thin standalone AOT).
 * Peer: JitCosKernel (#27005), JitCeilKernel (#27003).
 * php-src: ext/standard/math.c — PHP_FUNCTION(rad2deg)
 */
final class JitRad2degKernel
{
    /** @return Value double — rad2deg(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        $double = $context->getTypeFromString('double');

        return $context->builder->fmul(
            $num,
            $double->constReal(180.0 / \M_PI)
        );
    }
}
