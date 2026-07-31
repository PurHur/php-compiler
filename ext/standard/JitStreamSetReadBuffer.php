<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamBufferRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for stream_set_read_buffer() via __compiler_stream_set_read_buffer (issue #3755, #25924). */
final class JitStreamSetReadBuffer
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $bufferLong): Value
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        StreamBufferRuntime::ensureLinked($context);
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'stream_set_read_buffer_cont');
        }

        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_stream_set_read_buffer'),
            $handleLong,
            $bufferLong
        );

        return $context->builder->truncOrBitCast($ret, $context->getTypeFromString('int64'));
    }
}
