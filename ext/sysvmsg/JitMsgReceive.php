<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for msg_receive() — raw string path (unserialize=false) (#28432).
 */
final class JitMsgReceive
{
    /** @param list<JITVariable> $args */
    public static function invoke(Context $context, array $args): Value
    {
        $handle = JitMsgHandle::fromArg($context, $args[0], 'msg_receive');
        JitMsgHandle::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $typeSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $desired = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[1], 'msg_receive() desired_message_type'),
            $i64
        );
        $maxSize = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[3], 'msg_receive() max_message_size'),
            $i64
        );
        $unserialize = isset($args[5])
            ? JitBoolArg::lower($context, $args[5], 'msg_receive() unserialize')
            : $i1->constInt(1, false);

        $okBb = BasicBlockHelper::append($context, 'msg_rcv_unser_ok');
        $errBb = BasicBlockHelper::append($context, 'msg_rcv_unser_err');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $unserialize, $i1->constInt(0, false)),
            $okBb,
            $errBb
        );
        $context->builder->positionAtEnd($errBb);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError(
            $context,
            'msg_receive(): unserialize=true is not supported for JIT/AOT in this compiler build (issue #28432)'
        );
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBb);

        $typeOut = JitValueBox::valuePtrFromVariable($context, $args[2]);
        $msgOut = JitValueBox::valuePtrFromVariable($context, $args[4]);
        $str = $context->builder->call(
            $context->lookupFunction('__compiler_msg_receive'),
            $handle,
            $desired,
            $maxSize,
            $typeSlot
        );
        $typeVal = $context->builder->load($typeSlot);
        $context->builder->call($context->lookupFunction('__value__writeLong'), $typeOut, $typeVal);
        $context->builder->call($context->lookupFunction('__value__writeString'), $msgOut, $str);
        $ok = $context->builder->icmp(
            Builder::INT_NE,
            $typeVal,
            $i64->constInt(0, false)
        );
        // Also treat empty receive failure: type 0 means fail from our ABI
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $ok);

        return $ptr;
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        return JitMsgHandle::emitArgumentCountError(
            $context,
            $argc < 5
                ? 'msg_receive() expects at least 5 arguments, '.$argc.' given'
                : 'msg_receive() expects at most 8 arguments, '.$argc.' given'
        );
    }
}
