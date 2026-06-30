<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for explode() — builds a packed __hashtable__ of string parts.
 *
 * Mirrors VmString::explode() / php-src ext/standard/string.c (#4077 negative limit, #14019 binary-safe search).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitExplode
{
    private static int $seq = 0;

    /**
     * Compile-time explode for JIT/AOT when delimiter, haystack, and limit are known.
     */
    public static function buildPackedStrings(
        Context $context,
        string $delimiter,
        string $literal,
        int $limit
    ): Value {
        $parts = VmString::explode($delimiter, $literal, $limit);
        $ht = HashTableHelper::alloc($context);
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $sizeT = $context->getTypeFromString('size_t');
        foreach ($parts as $i => $part) {
            $slice = $context->builder->load($context->constantStringFromString($part));
            $context->builder->call(
                $setString,
                $ht,
                $sizeT->constInt($i, false),
                $slice
            );
        }

        return $ht;
    }

    public static function explode(Context $context, Value $delimiter, Value $haystack, ?Value $limit = null): Value
    {
        if (null === $limit) {
            return self::explodeUnlimited($context, $delimiter, $haystack);
        }

        $tag = 'ex'.(string) ++self::$seq;
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $limitSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($limit, $limitSlot);

        $ht = HashTableHelper::alloc($context);

        $dispatchBlock = BasicBlockHelper::append($context, 'explode_dispatch_'.$tag);
        $negBlock = BasicBlockHelper::append($context, 'explode_neg_'.$tag);
        $posEntryBlock = BasicBlockHelper::append($context, 'explode_pos_entry_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'explode_done_'.$tag);

        $context->builder->branch($dispatchBlock);

        $context->builder->positionAtEnd($dispatchBlock);
        $limitVal = $context->builder->load($limitSlot);
        $isNegative = $context->builder->icmp(Builder::INT_SLT, $limitVal, $zero);
        $context->builder->branchIf($isNegative, $negBlock, $posEntryBlock);

        $context->builder->positionAtEnd($negBlock);
        self::explodeNegativeLimit(
            $context,
            $delimiter,
            $haystack,
            $limitSlot,
            $ht,
            $doneBlock,
            $tag
        );

        $context->builder->positionAtEnd($posEntryBlock);
        $one = $i64->constInt(1, false);
        $limitLeOne = $context->builder->icmp(Builder::INT_SLE, $limitVal, $one);
        $singleBlock = BasicBlockHelper::append($context, 'explode_single_'.$tag);
        $posInitBlock = BasicBlockHelper::append($context, 'explode_pos_init_'.$tag);
        $context->builder->branchIf($limitLeOne, $singleBlock, $posInitBlock);

        $context->builder->positionAtEnd($singleBlock);
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call($setString, $ht, $sizeT->constInt(0, false), $haystack);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($posInitBlock);
        self::explodePositiveLimited(
            $context,
            $delimiter,
            $haystack,
            $limitVal,
            $tag,
            $ht,
            $doneBlock,
            $posInitBlock
        );

        $context->builder->positionAtEnd($doneBlock);
        BasicBlockHelper::branchToFreshContinue($context, 'explode_continue_'.$tag);

        return $ht;
    }

    /** No limit argument — split on every delimiter occurrence. */
    private static function explodeUnlimited(Context $context, Value $delimiter, Value $haystack): Value
    {
        $tag = 'ex'.(string) ++self::$seq;
        $map = $context->structFieldMap['__string__'];
        $delimLen = $context->builder->load(
            $context->builder->structGep($delimiter, $map['length'])
        );
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $hayPtr = $context->builder->structGep($haystack, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $sizeOne = $sizeT->constInt(1, false);

        $ht = HashTableHelper::alloc($context);
        $setString = $context->lookupFunction('__hashtable__setStringAt');

        $offsetSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $offsetSlot);
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);

        $loopHead = BasicBlockHelper::append($context, 'explode_head_'.$tag);
        $loopBody = BasicBlockHelper::append($context, 'explode_body_'.$tag);
        $tailBlock = BasicBlockHelper::append($context, 'explode_tail_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'explode_done_'.$tag);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $offset = $context->builder->load($offsetSlot);
        $foundI32 = self::findDelimiterOffsetI32($context, $haystack, $delimiter, $offset);
        $notFound = self::isDelimiterNotFound($context, $foundI32);
        $context->builder->branchIf($notFound, $tailBlock, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $pos = self::delimiterOffsetToI64($context, $foundI32);
        $partLen = $context->builder->sub($pos, $offset);
        $part = string_trim::jitCopySlice($context, $haystack, $hayPtr, $offset, $partLen);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setString, $ht, $idx, $part);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $sizeOne),
            $idxSlot
        );
        $newOffset = $context->builder->add($pos, $delimLen);
        $context->builder->store($newOffset, $offsetSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($tailBlock);
        $offset = $context->builder->load($offsetSlot);
        $tailLen = $context->builder->sub($hayLen, $offset);
        $part = string_trim::jitCopySlice($context, $haystack, $hayPtr, $offset, $tailLen);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setString, $ht, $idx, $part);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        BasicBlockHelper::branchToFreshContinue($context, 'explode_continue_'.$tag);

        return $ht;
    }

    /**
     * php-src php_explode_negative_limit() — runtime negative $limit (#4077).
     */
    private static function explodeNegativeLimit(
        Context $context,
        Value $delimiter,
        Value $haystack,
        Value $limitSlot,
        Value $ht,
        BasicBlock $doneBlock,
        string $tag
    ): void {
        $map = $context->structFieldMap['__string__'];
        $delimLen = $context->builder->load(
            $context->builder->structGep($delimiter, $map['length'])
        );
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $hayPtr = $context->builder->structGep($haystack, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $sizeOne = $sizeT->constInt(1, false);
        $setString = $context->lookupFunction('__hashtable__setStringAt');

        $foundSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $offsetSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $segIdxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $toReturnSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $startSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $endSlot = BasicBlockHelper::entryAlloca($context, $i64);

        $countHead = BasicBlockHelper::append($context, 'explode_neg_count_head_'.$tag);
        $countBody = BasicBlockHelper::append($context, 'explode_neg_count_body_'.$tag);
        $afterCount = BasicBlockHelper::append($context, 'explode_neg_after_count_'.$tag);
        $emptyBlock = BasicBlockHelper::append($context, 'explode_neg_empty_'.$tag);
        $segHead = BasicBlockHelper::append($context, 'explode_neg_seg_head_'.$tag);
        $segBody = BasicBlockHelper::append($context, 'explode_neg_seg_body_'.$tag);

        $context->builder->store($one, $foundSlot);
        $context->builder->store($zero, $offsetSlot);
        $context->builder->branch($countHead);

        $context->builder->positionAtEnd($countHead);
        $offset = $context->builder->load($offsetSlot);
        $foundI32 = self::findDelimiterOffsetI32($context, $haystack, $delimiter, $offset);
        $notFound = self::isDelimiterNotFound($context, $foundI32);
        $context->builder->branchIf($notFound, $afterCount, $countBody);

        $context->builder->positionAtEnd($countBody);
        $foundVal = $context->builder->load($foundSlot);
        $context->builder->store($context->builder->addNoSignedWrap($foundVal, $one), $foundSlot);
        $pos = self::delimiterOffsetToI64($context, $foundI32);
        $context->builder->store($context->builder->add($pos, $delimLen), $offsetSlot);
        $context->builder->branch($countHead);

        $context->builder->positionAtEnd($afterCount);
        $foundVal = $context->builder->load($foundSlot);
        $limitVal = $context->builder->load($limitSlot);
        $toReturn = $context->builder->add($foundVal, $limitVal);
        $context->builder->store($toReturn, $toReturnSlot);
        $toReturnLeZero = $context->builder->icmp(Builder::INT_SLE, $toReturn, $zero);
        $segInitBlock = BasicBlockHelper::append($context, 'explode_neg_seg_init_'.$tag);
        $context->builder->branchIf($toReturnLeZero, $emptyBlock, $segInitBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($segInitBlock);
        $context->builder->store($zero, $segIdxSlot);
        $context->builder->branch($segHead);

        $context->builder->positionAtEnd($segHead);
        $segIdx = $context->builder->load($segIdxSlot);
        $toReturnVal = $context->builder->load($toReturnSlot);
        $segDone = $context->builder->icmp(Builder::INT_SGE, $segIdx, $toReturnVal);
        $context->builder->branchIf($segDone, $doneBlock, $segBody);

        $afterStart = BasicBlockHelper::append($context, 'explode_neg_after_start_'.$tag);
        $endBlock = BasicBlockHelper::append($context, 'explode_neg_seg_end_'.$tag);
        $endTailBlock = BasicBlockHelper::append($context, 'explode_neg_seg_end_tail_'.$tag);
        $afterEnd = BasicBlockHelper::append($context, 'explode_neg_after_end_'.$tag);
        $segAppendBlock = BasicBlockHelper::append($context, 'explode_neg_seg_append_'.$tag);

        $context->builder->positionAtEnd($segBody);
        $segIdx = $context->builder->load($segIdxSlot);
        self::emitDelimiterWalkOffset(
            $context,
            $haystack,
            $delimiter,
            $hayPtr,
            $delimLen,
            $segIdx,
            $startSlot,
            $afterStart,
            $tag,
            '_start'
        );

        $context->builder->positionAtEnd($afterStart);
        $nextIdx = $context->builder->addNoSignedWrap($segIdx, $one);
        $foundVal = $context->builder->load($foundSlot);
        $hasNext = $context->builder->icmp(Builder::INT_SLT, $nextIdx, $foundVal);
        $context->builder->branchIf($hasNext, $endBlock, $endTailBlock);

        $context->builder->positionAtEnd($endBlock);
        self::emitDelimiterWalkOffset(
            $context,
            $haystack,
            $delimiter,
            $hayPtr,
            $delimLen,
            $nextIdx,
            $endSlot,
            $afterEnd,
            $tag,
            '_next'
        );

        $context->builder->positionAtEnd($afterEnd);
        $nextStart = $context->builder->load($endSlot);
        $context->builder->store($context->builder->sub($nextStart, $delimLen), $endSlot);
        $context->builder->branch($segAppendBlock);

        $context->builder->positionAtEnd($endTailBlock);
        $context->builder->store($hayLen, $endSlot);
        $context->builder->branch($segAppendBlock);

        $context->builder->positionAtEnd($segAppendBlock);
        $start = $context->builder->load($startSlot);
        $end = $context->builder->load($endSlot);
        $partLen = $context->builder->sub($end, $start);
        $part = string_trim::jitCopySlice($context, $haystack, $hayPtr, $start, $partLen);
        $htIdx = $context->builder->truncOrBitCast($segIdx, $sizeT);
        $context->builder->call($setString, $ht, $htIdx, $part);
        $context->builder->store(
            $context->builder->addNoSignedWrap($segIdx, $one),
            $segIdxSlot
        );
        $context->builder->branch($segHead);
    }

    /**
     * Store offset after walking $walkCount delimiters from the start of $hayPtr, then branch to $continueBlock.
     */
    private static function emitDelimiterWalkOffset(
        Context $context,
        Value $haystack,
        Value $delimiter,
        Value $hayPtr,
        Value $delimLen,
        Value $walkCount,
        Value $resultSlot,
        BasicBlock $continueBlock,
        string $tag,
        string $suffix
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $offsetSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $counterSlot = BasicBlockHelper::entryAlloca($context, $i64);

        $isZero = $context->builder->icmp(Builder::INT_EQ, $walkCount, $zero);
        $walkBlock = BasicBlockHelper::append($context, 'explode_walk_'.$tag.$suffix);
        $zeroBlock = BasicBlockHelper::append($context, 'explode_walk_zero_'.$tag.$suffix);
        $walkDone = BasicBlockHelper::append($context, 'explode_walk_done_'.$tag.$suffix);
        $head = BasicBlockHelper::append($context, 'explode_walk_head_'.$tag.$suffix);
        $body = BasicBlockHelper::append($context, 'explode_walk_body_'.$tag.$suffix);

        $context->builder->branchIf($isZero, $zeroBlock, $walkBlock);

        $context->builder->positionAtEnd($zeroBlock);
        $context->builder->store($zero, $resultSlot);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($walkBlock);
        $context->builder->store($zero, $offsetSlot);
        $context->builder->store($zero, $counterSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $counter = $context->builder->load($counterSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $counter, $walkCount);
        $context->builder->branchIf($atEnd, $walkDone, $body);

        $context->builder->positionAtEnd($body);
        $offset = $context->builder->load($offsetSlot);
        $foundI32 = self::findDelimiterOffsetI32($context, $haystack, $delimiter, $offset);
        $pos = self::delimiterOffsetToI64($context, $foundI32);
        $context->builder->store($context->builder->add($pos, $delimLen), $offsetSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($counter, $one),
            $counterSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($walkDone);
        $context->builder->store($context->builder->load($offsetSlot), $resultSlot);
        $context->builder->branch($continueBlock);
    }

    private static function explodePositiveLimited(
        Context $context,
        Value $delimiter,
        Value $haystack,
        Value $limitVal,
        string $tag,
        ?Value $ht = null,
        ?BasicBlock $doneBlock = null,
        ?BasicBlock $entryFromBlock = null
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $delimLen = $context->builder->load(
            $context->builder->structGep($delimiter, $map['length'])
        );
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $hayPtr = $context->builder->structGep($haystack, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $sizeOne = $sizeT->constInt(1, false);

        $ownResult = null === $ht;
        if ($ownResult) {
            $ht = HashTableHelper::alloc($context);
            $limitSlot = BasicBlockHelper::entryAlloca($context, $i64);
            $context->builder->store($limitVal, $limitSlot);

            $entryBlock = BasicBlockHelper::append($context, 'explode_entry_'.$tag);
            $singleBlock = BasicBlockHelper::append($context, 'explode_single_'.$tag);
            $posInitBlock = BasicBlockHelper::append($context, 'explode_pos_init_'.$tag);
            $doneBlock = BasicBlockHelper::append($context, 'explode_done_'.$tag);

            $context->builder->branch($entryBlock);
            $context->builder->positionAtEnd($entryBlock);
            $limitLeOne = $context->builder->icmp(Builder::INT_SLE, $limitVal, $one);
            $context->builder->branchIf($limitLeOne, $singleBlock, $posInitBlock);

            $setString = $context->lookupFunction('__hashtable__setStringAt');
            $context->builder->positionAtEnd($singleBlock);
            $context->builder->call($setString, $ht, $sizeT->constInt(0, false), $haystack);
            $context->builder->branch($doneBlock);
            $entryFromBlock = $posInitBlock;
        } else {
            $limitSlot = BasicBlockHelper::entryAlloca($context, $i64);
            $context->builder->store($limitVal, $limitSlot);
        }

        $offsetSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $setString = $context->lookupFunction('__hashtable__setStringAt');

        $loopHead = BasicBlockHelper::append($context, 'explode_head_'.$tag);
        $loopBody = BasicBlockHelper::append($context, 'explode_body_'.$tag);
        $limitDoneBlock = BasicBlockHelper::append($context, 'explode_limit_done_'.$tag);
        $continueBlock = BasicBlockHelper::append($context, 'explode_continue_check_'.$tag);
        $tailBlock = BasicBlockHelper::append($context, 'explode_tail_'.$tag);
        $appendEmptyBlock = BasicBlockHelper::append($context, 'explode_append_empty_'.$tag);
        if ($ownResult) {
            $doneBlock = $doneBlock ?? BasicBlockHelper::append($context, 'explode_done_'.$tag);
        }

        $context->builder->positionAtEnd($entryFromBlock);
        $context->builder->store($zero, $offsetSlot);
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $offset = $context->builder->load($offsetSlot);
        $foundI32 = self::findDelimiterOffsetI32($context, $haystack, $delimiter, $offset);
        $notFound = self::isDelimiterNotFound($context, $foundI32);
        $context->builder->branchIf($notFound, $tailBlock, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $pos = self::delimiterOffsetToI64($context, $foundI32);
        $partLen = $context->builder->sub($pos, $offset);
        $part = string_trim::jitCopySlice($context, $haystack, $hayPtr, $offset, $partLen);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setString, $ht, $idx, $part);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $sizeOne),
            $idxSlot
        );
        $newOffset = $context->builder->add($pos, $delimLen);
        $context->builder->store($newOffset, $offsetSlot);
        $lim = $context->builder->load($limitSlot);
        $newLim = $context->builder->sub($lim, $one);
        $context->builder->store($newLim, $limitSlot);
        $limitExhausted = $context->builder->icmp(Builder::INT_SLE, $newLim, $one);
        $pastEnd = $context->builder->icmp(Builder::INT_SGT, $newOffset, $hayLen);
        $context->builder->branchIf($limitExhausted, $limitDoneBlock, $continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->branchIf($pastEnd, $appendEmptyBlock, $loopHead);

        $context->builder->positionAtEnd($limitDoneBlock);
        $offset = $context->builder->load($offsetSlot);
        $tailLen = $context->builder->sub($hayLen, $offset);
        $part = string_trim::jitCopySlice($context, $haystack, $hayPtr, $offset, $tailLen);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setString, $ht, $idx, $part);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($appendEmptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setString, $ht, $idx, $emptyStr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($tailBlock);
        $offset = $context->builder->load($offsetSlot);
        $tailLen = $context->builder->sub($hayLen, $offset);
        $part = string_trim::jitCopySlice($context, $haystack, $hayPtr, $offset, $tailLen);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setString, $ht, $idx, $part);
        $context->builder->branch($doneBlock);

        if ($ownResult) {
            $context->builder->positionAtEnd($doneBlock);
            BasicBlockHelper::branchToFreshContinue($context, 'explode_continue_'.$tag);
        }

        return $ht;
    }

    private static function findDelimiterOffsetI32(
        Context $context,
        Value $haystack,
        Value $delimiter,
        Value $offset
    ): Value {
        return JitStringSearch::findOffsetI32($context, $haystack, $delimiter, $offset, false);
    }

    private static function isDelimiterNotFound(Context $context, Value $foundI32): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(
            Builder::INT_EQ,
            $foundI32,
            $i32->constInt(JitStringSearch::NOT_FOUND, true)
        );
    }

    private static function delimiterOffsetToI64(Context $context, Value $foundI32): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->zExt($foundI32, $i64);
    }
}
