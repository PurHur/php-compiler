<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for strrpos() — repeated strstr from offset for last match.
 *
 * Not found is represented as 0 (native long). VM mode returns boolean false instead.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrrpos
{
    public const NOT_FOUND = 0;

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
        $needleLen = $context->builder->load(
            $context->builder->structGep($needle, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $hayPtr = $context->builder->structGep($haystack, $map['value']);
        $needlePtr = $context->builder->structGep($needle, $map['value']);

        $startOffset = null === $offset ? $zero : self::clampIndex($context, $offset, $zero, $hayLen);
        $limit = $context->builder->sub($hayLen, $needleLen);

        $lastSlot = $context->builder->alloca($i64, 1, 'strrpos_last');
        $posSlot = $context->builder->alloca($i64, 1, 'strrpos_pos');
        $notFound = $i64->constInt(self::NOT_FOUND, false);
        $context->builder->store($notFound, $lastSlot);
        $context->builder->store($startOffset, $posSlot);

        $loopHead = BasicBlockHelper::append($context, 'strrpos_head');
        $loopBody = BasicBlockHelper::append($context, 'strrpos_body');
        $loopDone = BasicBlockHelper::append($context, 'strrpos_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $pos = $context->builder->load($posSlot);
        $pastLimit = $context->builder->icmp(Builder::INT_SGT, $pos, $limit);
        $context->builder->branchIf($pastLimit, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $searchPtr = $context->builder->inBoundsGEP($hayPtr, $pos);
        $found = $context->builder->call(
            $context->lookupFunction('strstr'),
            $searchPtr,
            $needlePtr
        );
        $null = $context->getTypeFromString('int8*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $found, $null);

        $notFoundBlock = BasicBlockHelper::append($context, 'strrpos_miss');
        $foundBlock = BasicBlockHelper::append($context, 'strrpos_hit');
        $context->builder->branchIf($isNull, $notFoundBlock, $foundBlock);

        $context->builder->positionAtEnd($foundBlock);
        $foundInt = $context->builder->ptrToInt($found, $i64);
        $baseInt = $context->builder->ptrToInt($hayPtr, $i64);
        $lastPos = $context->builder->sub($foundInt, $baseInt);
        $context->builder->store($lastPos, $lastSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($lastPos, $one),
            $posSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($notFoundBlock);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($loopDone);

        return $context->builder->load($lastSlot);
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
