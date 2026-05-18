<?php

declare(strict_types=1);

/**
 * LLVM helpers for string length/offset arithmetic (__string__ length is int64).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\Type;
use PHPLLVM\Value;

final class JitStringIndex
{
    public static function i64(Context $context): Type
    {
        return $context->getTypeFromString('int64');
    }

    public static function zero(Context $context): Value
    {
        return self::i64($context)->constInt(0, false);
    }

    public static function min(Context $context, Value $a, Value $b): Value
    {
        $cmp = $context->builder->icmp(Builder::INT_SLT, $a, $b);

        return $context->builder->select($cmp, $a, $b);
    }

    public static function max(Context $context, Value $a, Value $b): Value
    {
        $cmp = $context->builder->icmp(Builder::INT_SGT, $a, $b);

        return $context->builder->select($cmp, $a, $b);
    }

    public static function clamp(Context $context, Value $index, Value $min, Value $max): Value
    {
        return self::min($context, self::max($context, $index, $min), $max);
    }
}
