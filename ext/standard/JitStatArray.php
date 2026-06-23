<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stat()/lstat() via {@see __phpc_stat} (issue #1197). */
final class JitStatArray
{
    private static int $seq = 0;

    /** @return Value */
    public static function invoke(Context $context, Value $pathStr, bool $lstat): Value
    {
        $tag = ($lstat ? 'lstat' : 'stat').(string) ++self::$seq;
        $i32 = $context->getTypeFromString('int32');
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $ht = $context->builder->call(
            $context->lookupFunction('__phpc_stat'),
            $pathStr,
            $i32->constInt($lstat ? 1 : 0, false)
        );
        $failed = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtrTy->constNull());
        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $okBlock = BasicBlockHelper::append($context, $tag.'_ok');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitStat::warnPathStatArrayFailed($context, $pathStr, $lstat ? 'lstat' : 'stat', $lstat);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $okPtr, $ht);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($falsePtr, $failBlock);
        $result->addIncoming($okPtr, $okTail);

        return $result;
    }
}
