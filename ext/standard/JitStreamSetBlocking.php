<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamMeta;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_set_blocking() via __compiler_stream_set_blocking (issue #6007). */
final class JitStreamSetBlocking
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $modeLong): Value
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        StreamMeta::ensureLinked($context);
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'stream_set_blocking_cont');
        }

        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_stream_set_blocking'),
            $handleLong,
            $modeLong
        );
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
