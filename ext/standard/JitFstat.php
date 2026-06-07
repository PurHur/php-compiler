<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamPathRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for fstat() via stream path + stat() (issue #6764, #3482). */
final class JitFstat
{
    private static int $seq = 0;

    /** @return Value */
    public static function invoke(Context $context, Value $handle): Value
    {
        StreamPathRuntime::ensureLinked($context);

        $tag = 'fstat'.(string) ++self::$seq;
        $i64 = $context->getTypeFromString('int64');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $pathStr = $context->builder->call(
            $context->lookupFunction('__phpc_stream_path'),
            $context->builder->truncOrBitCast($handle, $i64)
        );
        $failed = $context->builder->icmp(Builder::INT_EQ, $pathStr, $strPtrTy->constNull());
        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $okBlock = BasicBlockHelper::append($context, $tag.'_ok');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $statPtr = JitStatArray::invoke($context, $pathStr, false);
        $statTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($valuePtrTy);
        $result->addIncoming($falsePtr, $failBlock);
        $result->addIncoming($statPtr, $statTail);

        return $result;
    }
}
