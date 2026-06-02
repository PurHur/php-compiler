<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_set_timeout() via __compiler_stream_set_timeout (issue #3754). */
final class JitStreamSetTimeout
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $secondsLong, Value $usecLong): Value
    {
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_stream_set_timeout'),
            $handleLong,
            $secondsLong,
            $usecLong
        );
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
