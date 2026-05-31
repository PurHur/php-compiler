<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for ftruncate() via __compiler_ftruncate (issue #3256). */
final class JitFtruncate
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $sizeLong): Value
    {
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_ftruncate'),
            $handleLong,
            $sizeLong
        );
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
