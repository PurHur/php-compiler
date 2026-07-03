<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamSocketAcceptRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stream_socket_accept() via __compiler_stream_socket_accept (#15346). */
final class JitStreamSocketAccept
{
    public static function invoke(
        Context $context,
        Value $handleLong,
        Value $hasTimeoutLong,
        Value $timeoutDouble
    ): Value {
        StreamSocketAcceptRuntime::ensureLinked($context);
        $accepted = $context->builder->call(
            $context->lookupFunction('__compiler_stream_socket_accept'),
            $handleLong,
            $hasTimeoutLong,
            $timeoutDouble
        );

        return self::boxedStreamHandleOrFalse($context, $accepted);
    }

    public static function lowerTimeoutArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readDouble'),
                $context->helper->loadValue($arg)
            );
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $longVal = $context->helper->loadValue($arg);

            return $context->builder->sitofp($longVal, $context->getTypeFromString('double'));
        }

        return $context->builder->sitofp(
            JitLongArg::lower($context, $arg, 'stream_socket_accept() timeout'),
            $context->getTypeFromString('double')
        );
    }

    private static function boxedStreamHandleOrFalse(Context $context, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $handle, $zero);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'stream_socket_accept_fail');
        $okBlock = BasicBlockHelper::append($context, 'stream_socket_accept_ok');
        $doneBlock = BasicBlockHelper::append($context, 'stream_socket_accept_done');
        $context->builder->branchIf($isZero, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $ptr,
            $handle
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
