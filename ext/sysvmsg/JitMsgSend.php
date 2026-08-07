<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for msg_send() — raw string path (serialize=false) (#28432).
 *
 * serialize=true is not supported in thin AOT yet.
 */
final class JitMsgSend
{
    /** @param list<JITVariable> $args */
    public static function invoke(Context $context, array $args): Value
    {
        $handle = JitMsgHandle::fromArg($context, $args[0], 'msg_send');
        JitMsgHandle::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $mtype = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[1], 'msg_send() message_type'),
            $i64
        );
        $serialize = isset($args[3])
            ? JitBoolArg::lower($context, $args[3], 'msg_send() serialize')
            : $i1->constInt(1, false);

        $serOk = BasicBlockHelper::append($context, 'msg_snd_ser_ok');
        $serErr = BasicBlockHelper::append($context, 'msg_snd_ser_err');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $serialize, $i1->constInt(0, false)),
            $serOk,
            $serErr
        );
        $context->builder->positionAtEnd($serErr);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError(
            $context,
            'msg_send(): serialize=true is not supported for JIT/AOT in this compiler build (issue #28432)'
        );
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($serOk);

        $message = JitStringBuiltinArg::lowerTypedString($context, $args[2], 'msg_send', 3, 'message');
        $blocking = isset($args[4])
            ? $context->builder->zext(
                JitBoolArg::lower($context, $args[4], 'msg_send() blocking'),
                $i64
            )
            : $i64->constInt(1, false);
        $rc = $context->builder->call(
            $context->lookupFunction('__compiler_msg_send'),
            $handle,
            $mtype,
            $message,
            $blocking
        );
        $ok = $context->builder->icmp(Builder::INT_NE, $rc, $i64->constInt(0, false));
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $ok);

        return $ptr;
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        return JitMsgHandle::emitArgumentCountError(
            $context,
            $argc < 3
                ? 'msg_send() expects at least 3 arguments, '.$argc.' given'
                : 'msg_send() expects at most 6 arguments, '.$argc.' given'
        );
    }
}
