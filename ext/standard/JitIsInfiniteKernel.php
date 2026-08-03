<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for is_infinite — oeq ±Inf bit patterns (#27021).
 * php-src: ext/standard/math.c — PHP_FUNCTION(is_infinite)
 */
final class JitIsInfiniteKernel
{
    private const POS_INF_BITS = 9218868437227405312; // 0x7FF0000000000000
    private const NEG_INF_BITS = -4503599627370496; // 0xFFF0000000000000

    /** @return Value int1 */
    public static function invoke(Context $context, Value $num): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $posInf = $context->builder->bitCast($i64->constInt(self::POS_INF_BITS, false), $double);
        $negInf = $context->builder->bitCast($i64->constInt(self::NEG_INF_BITS, true), $double);
        $pos = $context->builder->fcmp(Builder::REAL_OEQ, $num, $posInf);
        $neg = $context->builder->fcmp(Builder::REAL_OEQ, $num, $negInf);

        return $context->builder->or($pos, $neg);
    }
}
