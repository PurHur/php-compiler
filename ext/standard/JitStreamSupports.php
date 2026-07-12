<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamCaps;
use PHPCompiler\JIT\Builtin\StreamIoRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_supports() via __compiler_stream_supports (issue #5062). */
final class JitStreamSupports
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $featureLong): Value
    {
        StreamIoRuntime::ensureLinkedForUserScriptLowering($context);
        StreamCaps::ensureLinked($context);
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_stream_supports'),
            $handleLong,
            $featureLong
        );
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
