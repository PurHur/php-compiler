<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for is_finite — ordered and not ±Inf (#27021).
 * php-src: ext/standard/math.c — PHP_FUNCTION(is_finite)
 */
final class JitIsFiniteKernel
{
    /** @return Value int1 */
    public static function invoke(Context $context, Value $num): Value
    {
        $notNan = $context->builder->fcmp(Builder::REAL_ORD, $num, $num);
        $inf = JitIsInfiniteKernel::invoke($context, $num);
        $i1 = $context->getTypeFromString('int1');
        $notInf = $context->builder->icmp(Builder::INT_EQ, $inf, $i1->constInt(0, false));

        return $context->builder->and($notNan, $notInf);
    }
}
