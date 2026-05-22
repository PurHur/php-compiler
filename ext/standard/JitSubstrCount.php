<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for substr_count() — repeated strstr with non-overlapping advance.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
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
        $end = $hayLen;
        if (null !== $length) {
            $end = self::clampIndex(
                $context,
                $context->builder->addNoSignedWrap($startOffset, $length),
                $zero,
                $hayLen
            );
        }

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

    private static function clampIndex(Context $context, Value $index, Value $min, Value $max): Value
    {
        $cmpLo = $context->builder->icmp(Builder::INT_SLT, $index, $min);

        return $context->builder->select(
            $cmpLo,
            $min,
            $context->builder->select(
                $context->builder->icmp(Builder::INT_SGT, $index, $max),
                $max,
                $index
            )
        );
    }
}
