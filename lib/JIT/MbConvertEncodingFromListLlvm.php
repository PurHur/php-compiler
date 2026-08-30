<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\MbConvertEncodingRuntime;
use PHPCompiler\JIT\Builtin\MbConvertVariablesRuntime;
use PHPCompiler\JIT\ExceptionBridge;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM mb_convert_encoding() runtime array $from_encoding on string operand (#35296).
 *
 * NestedJIT cannot build PHP arrays/strings in helpers under thin AOT (peer #34358).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_encoding)
 */
final class MbConvertEncodingFromListLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    public static function convert(
        Context $context,
        Value $str,
        Value $toPtr,
        Variable $fromArg
    ): Value {
        MbConvertEncodingRuntime::ensureLinked($context);
        MbConvertVariablesRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_encoding_from_list');

        $ht = HashTableReadLlvm::loadHashtablePointer($context, $fromArg);
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $htMap['nextFreeElement'])
        );

        $tag = (string) self::nextSeq();
        $emptyBb = BasicBlockHelper::append($context, 'mbce_fl_empty_'.$tag);
        $singleBb = BasicBlockHelper::append($context, 'mbce_fl_single_'.$tag);
        $multiBb = BasicBlockHelper::append($context, 'mbce_fl_multi_'.$tag);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $context->builder->branchIf($isEmpty, $emptyBb, $singleBb);

        $context->builder->positionAtEnd($emptyBb);
        ExceptionBridge::ensureLinked($context);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'mb_convert_encoding(): Argument #3 ($from_encoding) must specify at least one encoding'
        );

        $context->builder->positionAtEnd($singleBb);
        $isSingle = $context->builder->icmp(Builder::INT_EQ, $nextFree, $one);
        $singleWork = BasicBlockHelper::append($context, 'mbce_fl_single_work_'.$tag);
        $mergeBb = BasicBlockHelper::append($context, 'mbce_fl_merge_'.$tag);
        $context->builder->branchIf($isSingle, $singleWork, $multiBb);

        $context->builder->positionAtEnd($singleWork);
        $fromPtr = HashTableReadLlvm::readStringAt($context, $ht, $zero);
        $context->builder->call(
            MbConvertEncodingRuntime::assertFromEncodingHelper($context),
            $fromPtr
        );
        $singleResult = MbConvertEncodingRuntime::callConvert(
            $context,
            $str,
            $toPtr,
            $fromPtr
        );
        $singleVal = self::materializeOwnedString($context, $singleResult);
        $singleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($multiBb);
        $multiVal = self::convertMulti($context, $str, $toPtr, $fromArg, $tag);
        $multiEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($singleVal->typeOf());
        $phi->addIncoming($singleVal, $singleEnd);
        $phi->addIncoming($multiVal, $multiEnd);

        return $phi;
    }

    private static function convertMulti(
        Context $context,
        Value $str,
        Value $toPtr,
        Variable $fromArg,
        string $tag
    ): Value {
        // Runtime CSV + NestedJIT detect-then-convert (peer #35315) — avoids invalid IR from
        // __string__alloc order-code builder on size_t→int64 mismatch (#35296 leftover).
        $fromCsv = MbConvertVariablesFromListLlvm::buildFromCsv($context, $fromArg);
        $resultStr = MbConvertVariablesRuntime::callConvertString(
            $context,
            $str,
            $toPtr,
            $fromCsv
        );
        $zero = $context->getTypeFromString('int64')->constInt(0, false);
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $resultStr);
        $missBb = BasicBlockHelper::append($context, 'mbce_fl_multi_miss_'.$tag);
        $hitBb = BasicBlockHelper::append($context, 'mbce_fl_multi_hit_'.$tag);
        $isMiss = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $context->builder->branchIf($isMiss, $missBb, $hitBb);

        $context->builder->positionAtEnd($missBb);
        $missVal = self::foldFalse($context);
        $missEnd = $context->builder->getInsertBlock();
        $doneBb = BasicBlockHelper::append($context, 'mbce_fl_multi_done_'.$tag);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($hitBb);
        $hitVal = self::materializeOwnedString($context, $resultStr);
        $hitEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($hitVal->typeOf());
        $phi->addIncoming($missVal, $missEnd);
        $phi->addIncoming($hitVal, $hitEnd);

        return $phi;
    }

    private static function materializeOwnedString(Context $context, Value $resultStr): Value
    {
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );

        return $ptr;
    }

    private static function foldFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }
}
