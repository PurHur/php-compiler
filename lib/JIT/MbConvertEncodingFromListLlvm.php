<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\MbConvertEncodingRuntime;
use PHPCompiler\JIT\Builtin\MbDetectEncodingRuntime;
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
        MbDetectEncodingRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_convert_encoding_from_list');

        $ht = HashTableReadLlvm::loadHashtablePointer($context, $fromArg);
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
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
        $singleResult = $context->builder->call(
            MbConvertEncodingRuntime::convertHelper($context),
            $str,
            $toPtr,
            $fromPtr
        );
        $singleVal = self::materializeOwnedString($context, $singleResult);
        $singleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($multiBb);
        $multiVal = self::convertMulti($context, $str, $toPtr, $ht, $nextFree, $tag);
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
        Value $ht,
        Value $count,
        string $tag
    ): Value {
        $orderPtr = self::buildOrderCodesString($context, $ht, $count, $tag);
        $i64 = $context->getTypeFromString('int64');
        $detected = $context->builder->call(
            MbDetectEncodingRuntime::detectHelper($context),
            $str,
            $orderPtr,
            $i64->constInt(0, false)
        );
        $strMap = $context->structFieldMap['__string__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $detLen = $context->builder->load($context->builder->structGep($detected, $strMap['length']));
        $missBb = BasicBlockHelper::append($context, 'mbce_fl_multi_miss_'.$tag);
        $hitBb = BasicBlockHelper::append($context, 'mbce_fl_multi_hit_'.$tag);
        $detMiss = $context->builder->icmp(Builder::INT_EQ, $detLen, $zero);
        $context->builder->branchIf($detMiss, $missBb, $hitBb);

        $context->builder->positionAtEnd($missBb);
        $missVal = self::foldFalse($context);
        $missEnd = $context->builder->getInsertBlock();
        $doneBb = BasicBlockHelper::append($context, 'mbce_fl_multi_done_'.$tag);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($hitBb);
        $context->builder->call(
            MbConvertEncodingRuntime::assertFromEncodingHelper($context),
            $detected
        );
        $result = $context->builder->call(
            MbConvertEncodingRuntime::convertHelper($context),
            $str,
            $toPtr,
            $detected
        );
        $hitVal = self::materializeOwnedString($context, $result);
        $hitEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($hitVal->typeOf());
        $phi->addIncoming($missVal, $missEnd);
        $phi->addIncoming($hitVal, $hitEnd);

        return $phi;
    }

    private static function buildOrderCodesString(
        Context $context,
        Value $ht,
        Value $count,
        string $tag
    ): Value {
        $strMap = $context->structFieldMap['__string__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $orderStr = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $count
        );
        $dest = $context->builder->structGep($orderStr, $strMap['value']);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'mbce_fl_ord_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'mbce_fl_ord_body_'.$tag);
        $work = BasicBlockHelper::append($context, 'mbce_fl_ord_work_'.$tag);
        $next = BasicBlockHelper::append($context, 'mbce_fl_ord_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'mbce_fl_ord_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($isSet, $work, $next);

        $context->builder->positionAtEnd($work);
        $encPtr = HashTableReadLlvm::readStringAt($context, $ht, $idx);
        $context->builder->call(
            MbConvertEncodingRuntime::assertFromEncodingHelper($context),
            $encPtr
        );
        $code = self::encodingOrderCode($context, $encPtr, $tag.'_i');
        $context->builder->store($code, $context->builder->gep($dest, $idx));
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $orderStr;
    }

    private static function encodingOrderCode(Context $context, Value $encPtr, string $tag): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $latinBb = BasicBlockHelper::append($context, 'mbce_fl_latin_'.$tag);
        $doneBb = BasicBlockHelper::append($context, 'mbce_fl_code_done_'.$tag);
        $slot = BasicBlockHelper::entryAlloca($context, $i8);
        $nextBb = $context->builder->getInsertBlock();

        $tests = [
            'UTF-8' => ord('U'),
            'UTF8' => ord('U'),
            'ASCII' => ord('A'),
            'US-ASCII' => ord('A'),
        ];
        foreach ($tests as $lit => $ord) {
            $tryBb = BasicBlockHelper::append($context, 'mbce_fl_try_'.$tag.'_'.$ord);
            $fallBb = BasicBlockHelper::append($context, 'mbce_fl_fall_'.$tag.'_'.$ord);
            $context->builder->positionAtEnd($nextBb);
            $litPtr = $context->builder->load($context->constantStringFromString($lit));
            $cmp = JitStringCompare::strcmp($context, $encPtr, $litPtr);
            $zero = $cmp->typeOf()->constInt(0, false);
            $isEq = $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);
            $context->builder->branchIf($isEq, $tryBb, $fallBb);
            $context->builder->positionAtEnd($tryBb);
            $context->builder->store($i8->constInt($ord, false), $slot);
            $context->builder->branch($doneBb);
            $nextBb = $fallBb;
        }

        $context->builder->positionAtEnd($nextBb);
        $context->builder->branch($latinBb);
        $context->builder->positionAtEnd($latinBb);
        $context->builder->store($i8->constInt(ord('L'), false), $slot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($slot);
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
