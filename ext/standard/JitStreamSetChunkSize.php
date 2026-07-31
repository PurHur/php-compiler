<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamBufferRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for stream_set_chunk_size() via __compiler_stream_set_chunk_size (issue #3754, #25924). */
final class JitStreamSetChunkSize
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $chunkSizeLong): Value
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        StreamBufferRuntime::ensureLinked($context);
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'stream_set_chunk_size_cont');
        }

        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_stream_set_chunk_size'),
            $handleLong,
            $chunkSizeLong
        );

        return $context->builder->truncOrBitCast($ret, $context->getTypeFromString('int64'));
    }
}
