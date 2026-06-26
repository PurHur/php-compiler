<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamSocketGetNameRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_socket_get_name() via __compiler_stream_socket_get_name (#12223). */
final class JitStreamSocketGetName
{
    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $wantPeerBool): Value
    {
        StreamSocketGetNameRuntime::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $wantPeer = $context->builder->zExt($wantPeerBool, $i64);
        $name = $context->builder->call(
            $context->lookupFunction('__compiler_stream_socket_get_name'),
            $handleLong,
            $wantPeer
        );

        return self::boxedStringOrFalse($context, $name);
    }

    private static function boxedStringOrFalse(Context $context, Value $str): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $str, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'stream_socket_get_name_fail');
        $okBlock = BasicBlockHelper::append($context, 'stream_socket_get_name_ok');
        $doneBlock = BasicBlockHelper::append($context, 'stream_socket_get_name_done');
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
