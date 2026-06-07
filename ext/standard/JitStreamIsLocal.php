<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_is_local() via __compiler_stream_is_local (issue #6173). */
final class JitStreamIsLocal
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_stream_is_local'),
            $handleLong
        );
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
