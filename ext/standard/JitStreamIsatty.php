<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamCaps;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_isatty() via __compiler_stream_isatty (issue #6035). */
final class JitStreamIsatty
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        StreamCaps::ensureLinked($context);
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        }
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_stream_isatty'),
            $handleLong
        );
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
