<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamFstat;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fstat() via __compiler_fstat / FstatJitHelper PHP (#10460, #3482). */
final class JitFstat
{
    /** @return Value */
    public static function invoke(Context $context, Value $handle): Value
    {
        StreamFstat::ensureLinked($context);

        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtrTy->constNull();
        $statHt = $context->builder->call(
            $context->lookupFunction('__compiler_fstat'),
            $context->builder->truncOrBitCast($handle, $context->getTypeFromString('int64'))
        );
        $failed = $context->builder->icmp(Builder::INT_EQ, $statHt, $nullHt);
        $failBlock = BasicBlockHelper::append($context, 'fstat_fail');
        $okBlock = BasicBlockHelper::append($context, 'fstat_ok');
        $doneBlock = BasicBlockHelper::append($context, 'fstat_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $arraySlot = JitValueBox::alloc($context);
        $arrayPtr = JitValueBox::pointer($context, $arraySlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $arrayPtr,
            $statHt
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($falsePtr, $failBlock);
        $result->addIncoming($arrayPtr, $okTail);

        return $result;
    }
}
