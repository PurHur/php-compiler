<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringMsg;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for msg_get_queue() (#28432). */
final class JitMsgGet
{
    /** @param list<JITVariable> $args */
    public static function invoke(Context $context, array $args): Value
    {
        StringMsg::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $key = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'msg_get_queue() key'),
            $i64
        );
        $perm = isset($args[1])
            ? $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'msg_get_queue() permissions'),
                $i64
            )
            : $i64->constInt(0o666, false);

        $objPtr = self::allocateQueueObject($context);
        $voidp = $context->getTypeFromString('void')->pointerType(0);
        $objAddr = $context->builder->ptrToInt(
            $context->builder->pointerCast($objPtr, $voidp),
            $i64
        );
        $rc = $context->builder->call(
            $context->lookupFunction('__compiler_msg_get_register'),
            $objAddr,
            $key,
            $perm
        );
        $ok = $context->builder->icmp(Builder::INT_NE, $rc, $i64->constInt(0, false));
        $failBb = BasicBlockHelper::append($context, 'msg_get_fail');
        $okBb = BasicBlockHelper::append($context, 'msg_get_ok');
        $doneBb = BasicBlockHelper::append($context, 'msg_get_done');
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeObject'), $ptr, $objPtr);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $result = $context->builder->phi($context->getTypeFromString('__value__*'));
        $result->addIncoming($falsePtr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    private static function allocateQueueObject(Context $context): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('SysvMessageQueue');
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        return $obj;
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError(
            $context,
            $argc < 1
                ? 'msg_get_queue() expects at least 1 argument, '.$argc.' given'
                : 'msg_get_queue() expects at most 2 arguments, '.$argc.' given'
        );
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }
}
