<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for strpos() — strstr-based search with optional byte offset.
 *
 * Returns a boxed {@see __value__*} (long position or native bool false when not found).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrpos
{
    public static function find(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $offset = null
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $hayPtr = $context->builder->structGep($haystack, $map['value']);
        $needlePtr = $context->builder->structGep($needle, $map['value']);
        $searchPtr = $hayPtr;
        if (null !== $offset) {
            $clamped = self::clampIndex($context, $offset, $zero, $hayLen);
            $searchPtr = $context->builder->inBoundsGEP($hayPtr, $clamped);
        }

        $found = $context->builder->call(
            $context->lookupFunction('strstr'),
            $searchPtr,
            $needlePtr
        );
        $null = $context->getTypeFromString('int8*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $found, $null);

        $slot = JitValueBox::alloc($context);
        $notFoundBlock = BasicBlockHelper::append($context, 'strpos_not_found');
        $foundBlock = BasicBlockHelper::append($context, 'strpos_found');
        $mergeBlock = BasicBlockHelper::append($context, 'strpos_merge');
        $context->builder->branchIf($isNull, $notFoundBlock, $foundBlock);

        $context->builder->positionAtEnd($notFoundBlock);
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($foundBlock);
        $foundInt = $context->builder->ptrToInt($found, $i64);
        $baseInt = $context->builder->ptrToInt($hayPtr, $i64);
        $pos = $context->builder->sub($foundInt, $baseInt);
        JitValueBox::writeLong($context, $slot, $pos);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return JitValueBox::pointer($context, $slot);
    }

    private static function clampIndex(Context $context, Value $index, Value $min, Value $max): Value
    {
        return self::minValue($context, self::maxValue($context, $index, $min), $max);
    }

    private static function minValue(Context $context, Value $a, Value $b): Value
    {
        $cmp = $context->builder->icmp(Builder::INT_SLT, $a, $b);

        return $context->builder->select($cmp, $a, $b);
    }

    private static function maxValue(Context $context, Value $a, Value $b): Value
    {
        $cmp = $context->builder->icmp(Builder::INT_SGT, $a, $b);

        return $context->builder->select($cmp, $a, $b);
    }
}
