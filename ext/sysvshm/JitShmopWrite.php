<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for shmop_write() (#27408). */
final class JitShmopWrite
{
    public static function invoke(
        Context $context,
        JITVariable $shmopArg,
        JITVariable $dataArg,
        JITVariable $offsetArg
    ): Value {
        $handle = JitShmopHandle::fromArg($context, $shmopArg, 'shmop_write');
        JitShmopHandle::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $data = JitStringBuiltinArg::lowerTypedString($context, $dataArg, 'shmop_write', 2, 'data');
        $offset = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $offsetArg, 'shmop_write() offset'),
            $i64
        );
        $n = $context->builder->call(
            $context->lookupFunction('__compiler_shmop_write'),
            $handle,
            $data,
            $offset
        );
        $ok = $context->builder->icmp(Builder::INT_SGE, $n, $i64->constInt(0, true));

        $failBb = BasicBlockHelper::append($context, 'shmop_write_fail');
        $okBb = BasicBlockHelper::append($context, 'shmop_write_ok');
        $doneBb = BasicBlockHelper::append($context, 'shmop_write_done');
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
        JitValueBox::writeLong($context, $slot, $n);
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
            'shmop_write() expects exactly 3 arguments, '.$argc.' given'
        );
    }
}
