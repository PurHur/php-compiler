<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for stream_set_read_buffer() via __compiler_stream_set_read_buffer (issue #3755). */
final class JitStreamSetReadBuffer
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $bufferLong): Value
    {
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_stream_set_read_buffer'),
            $handleLong,
            $bufferLong
        );

        return $context->builder->truncOrBitCast($ret, $context->getTypeFromString('int64'));
    }
}
