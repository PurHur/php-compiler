<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for strpos() — strstr-based search with optional byte offset.
 *
 * Not found is represented as -1 (native long). For strict comparison with false
 * in JIT/AOT, use a boxed strpos wrapper or VM; echo and numeric uses work with long.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrpos
{
    private const NOT_FOUND = -1;

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

        $notFoundBlock = BasicBlockHelper::append($context, 'strpos_not_found');
        $foundBlock = BasicBlockHelper::append($context, 'strpos_found');
        $mergeBlock = BasicBlockHelper::append($context, 'strpos_merge');
        $context->builder->branchIf($isNull, $notFoundBlock, $foundBlock);

        $context->builder->positionAtEnd($notFoundBlock);
        $sentinel = $i64->constInt(self::NOT_FOUND, false);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($foundBlock);
        $foundInt = $context->builder->ptrToInt($found, $i64);
        $baseInt = $context->builder->ptrToInt($hayPtr, $i64);
        $pos = $context->builder->sub($foundInt, $baseInt);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($sentinel, $notFoundBlock);
        $phi->addIncoming($pos, $foundBlock);

        return $phi;
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
