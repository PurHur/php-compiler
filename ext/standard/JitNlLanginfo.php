<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for nl_langinfo() via libc nl_langinfo(3) (#3382). */
final class JitNlLanginfo
{
    public static function invoke(Context $context, JITVariable $item): Value
    {
        $itemVal = self::jitIntArg($context, $item);
        $raw = $context->builder->call(
            $context->lookupFunction('nl_langinfo'),
            $itemVal
        );

        $charPtr = $context->getTypeFromString('char*');
        $nullPtr = $context->builder->pointerCast(
            $context->constantFromString(''),
            $charPtr
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $nullPtr);
        $emptyBb = BasicBlockHelper::append($context, 'nl_langinfo_false');
        $checkBb = BasicBlockHelper::append($context, 'nl_langinfo_check_empty');
        $emitBb = BasicBlockHelper::append($context, 'nl_langinfo_emit');
        $doneBb = BasicBlockHelper::append($context, 'nl_langinfo_done');

        $context->builder->branchIf($isNull, $emptyBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $first = $context->builder->load($raw);
        $isEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $first,
            $context->getTypeFromString('int8')->constInt(0, false)
        );
        $context->builder->branchIf($isEmpty, $emptyBb, $emitBb);

        $context->builder->positionAtEnd($emptyBb);
        $falsePtr = self::writeBool($context, false);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($emitBb);
        $len = $context->builder->call(
            $context->lookupFunction('strlen'),
            $raw
        );
        $i64 = $context->getTypeFromString('int64');
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $raw
        );
        $truePtr = JitValueBox::alloc($context);
        $trueVal = JitValueBox::pointer($context, $truePtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $trueVal,
            $str
        );
        $emitEndBb = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($falsePtr, $emptyBb);
        $result->addIncoming($trueVal, $emitEndBb);

        return $result;
    }

    private static function jitIntArg(Context $context, JITVariable $arg): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->trunc(
            JitSleep::zParamLong($context, $arg, 'nl_langinfo', 1, 'item'),
            $i32
        );
    }

    private static function writeBool(Context $context, bool $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $ptr,
            $i32->constInt($value ? 1 : 0, false)
        );

        return $ptr;
    }
}
