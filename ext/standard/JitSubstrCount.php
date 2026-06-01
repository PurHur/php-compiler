<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for substr_count() — AOT uses phpc_substr_count; MCJIT uses inline strstr loop.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\StringSubstrCount;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitSubstrCount
{
    private static int $blockSerial = 0;

    public static function count(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $offset = null,
        ?Value $length = null
    ): Value {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return self::countViaRuntime($context, $haystack, $needle, $offset, $length);
        }

        return self::countInline($context, $haystack, $needle, $offset, $length);
    }

    private static function countViaRuntime(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $offset,
        ?Value $length
    ): Value {
        StringSubstrCount::ensureLinked($context);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);

        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $needleLen = $context->builder->load(
            $context->builder->structGep($needle, $map['length'])
        );
        $hayPtr = $context->builder->structGep($haystack, $map['value']);
        $needlePtr = $context->builder->structGep($needle, $map['value']);
        $offsetVal = null === $offset ? $zero : $offset;
        $lengthVal = null === $length ? $zero : $length;
        $lengthIsNull = $i32->constInt(null === $length ? 1 : 0, false);
        $fn = $context->lookupFunction('phpc_substr_count');

        return $context->builder->call(
            $fn,
            $hayPtr,
            $context->builder->truncOrBitCast($hayLen, $sizeT),
            $needlePtr,
            $context->builder->truncOrBitCast($needleLen, $sizeT),
            $offsetVal,
            $lengthVal,
            $lengthIsNull
        );
    }

    private static function countInline(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $offset,
        ?Value $length
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

        [$startOffset, $end] = self::normalizeWindow($context, $hayLen, $offset, $length);

        $limit = $context->builder->sub($end, $needleLen);
        $pastStart = $context->builder->icmp(Builder::INT_SLT, $limit, $startOffset);

        $id = (string) (++self::$blockSerial);
        $countSlot = $context->builder->alloca($i64, 1, 'substr_count_n_'.$id);
        $posSlot = $context->builder->alloca($i64, 1, 'substr_count_pos_'.$id);
        $context->builder->store($zero, $countSlot);
        $context->builder->store($startOffset, $posSlot);

        $earlyDone = BasicBlockHelper::append($context, 'substr_count_early_'.$id);
        $loopHead = BasicBlockHelper::append($context, 'substr_count_head_'.$id);
        $loopDone = BasicBlockHelper::append($context, 'substr_count_done_'.$id);
        $merge = BasicBlockHelper::append($context, 'substr_count_merge_'.$id);

        $context->builder->branchIf($pastStart, $earlyDone, $loopHead);

        $context->builder->positionAtEnd($loopHead);
        $loopBody = BasicBlockHelper::append($context, 'substr_count_body_'.$id);

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

        $missBlock = BasicBlockHelper::append($context, 'substr_count_miss_'.$id);
        $hitBlock = BasicBlockHelper::append($context, 'substr_count_hit_'.$id);
        $context->builder->branchIf($isNull, $missBlock, $hitBlock);

        $context->builder->positionAtEnd($hitBlock);
        $foundInt = $context->builder->ptrToInt($found, $i64);
        $baseInt = $context->builder->ptrToInt($hayPtr, $i64);
        $foundPos = $context->builder->sub($foundInt, $baseInt);
        $beyondLimit = $context->builder->icmp(Builder::INT_SGT, $foundPos, $limit);
        $stopBlock = BasicBlockHelper::append($context, 'substr_count_stop_'.$id);
        $advanceBlock = BasicBlockHelper::append($context, 'substr_count_advance_'.$id);
        $context->builder->branchIf($beyondLimit, $stopBlock, $advanceBlock);

        $context->builder->positionAtEnd($advanceBlock);
        $count = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($count, $one),
            $countSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($foundPos, $needleLen),
            $posSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($stopBlock);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($missBlock);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($loopDone);
        $loopResult = $context->builder->load($countSlot);
        $loopDoneBlock = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($earlyDone);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $donePhi = $context->builder->phi($i64);
        $donePhi->addIncoming($zero, $earlyDone);
        $donePhi->addIncoming($loopResult, $loopDoneBlock);

        return $donePhi;
    }

    /**
     * @return array{0: Value, 1: Value} start offset and exclusive end (php-src php_substr_count window)
     */
    private static function normalizeWindow(
        Context $context,
        Value $hayLen,
        ?Value $offset,
        ?Value $length
    ): array {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $startOffset = $zero;
        $searchLen = $hayLen;

        if (null !== $offset) {
            $normOffset = $offset;
            $isNegative = $context->builder->icmp(Builder::INT_SLT, $normOffset, $zero);
            $normOffset = $context->builder->select(
                $isNegative,
                $context->builder->addNoSignedWrap($normOffset, $hayLen),
                $normOffset
            );
            $startOffset = $normOffset;
            $searchLen = $context->builder->sub($hayLen, $startOffset);
        }

        if (null !== $length) {
            $normLen = $length;
            $isNegative = $context->builder->icmp(Builder::INT_SLT, $normLen, $zero);
            $normLen = $context->builder->select(
                $isNegative,
                $context->builder->addNoSignedWrap($normLen, $searchLen),
                $normLen
            );
            $normLen = $context->builder->select(
                $context->builder->icmp(Builder::INT_SLT, $normLen, $zero),
                $zero,
                $normLen
            );
            $normLen = self::clampMax($context, $normLen, $searchLen);
            $searchLen = $normLen;
        }

        $end = $context->builder->addNoSignedWrap($startOffset, $searchLen);

        return [$startOffset, $end];
    }

    private static function clampMax(Context $context, Value $index, Value $max): Value
    {
        return $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $index, $max),
            $max,
            $index
        );
    }
}
