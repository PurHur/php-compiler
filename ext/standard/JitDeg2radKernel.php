<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM lowering for deg2rad leaf — fmul by (M_PI/180) (#26996).
 *
 * Used inside Deg2radJitHelper / NestedJIT so helpers do not call VmMath
 * (NestedJIT stubs cross-class calls to null→0 under thin standalone AOT).
 * Peer: JitCosKernel (#27005), JitCeilKernel (#27003).
 * php-src: ext/standard/math.c — PHP_FUNCTION(deg2rad)
 */
final class JitDeg2radKernel
{
    /** @return Value double — deg2rad(num) */
    public static function invoke(Context $context, Value $num): Value
    {
        $double = $context->getTypeFromString('double');

        return $context->builder->fmul(
            $num,
            $double->constReal(\M_PI / 180.0)
        );
    }
}
