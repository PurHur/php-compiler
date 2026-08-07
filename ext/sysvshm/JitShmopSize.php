<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for shmop_size() (#27408). */
final class JitShmopSize
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        $handle = JitShmopHandle::fromArg($context, $arg, 'shmop_size');
        JitShmopHandle::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $size = $context->builder->call(
            $context->lookupFunction('__compiler_shmop_size'),
            $handle
        );
        $ok = $context->builder->icmp(Builder::INT_SGE, $size, $i64->constInt(0, true));

        $failBb = BasicBlockHelper::append($context, 'shmop_size_fail');
        $okBb = BasicBlockHelper::append($context, 'shmop_size_ok');
        $doneBb = BasicBlockHelper::append($context, 'shmop_size_done');
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
        JitValueBox::writeLong($context, $slot, $size);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($falsePtr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        return JitShmopHandle::emitArgumentCountError(
            $context,
            'shmop_size() expects exactly 1 argument, '.$argc.' given'
        );
    }
}
