<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for explode() — builds a packed __hashtable__ of string parts.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
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
        $tag = 'ex'.(string) ++self::$seq;
        $map = $context->structFieldMap['__string__'];
        $delimLen = $context->builder->load(
            $context->builder->structGep($delimiter, $map['length'])
        );
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $delimPtr = $context->builder->structGep($delimiter, $map['value']);
        $hayPtr = $context->builder->structGep($haystack, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $sizeOne = $sizeT->constInt(1, false);
        $maxLimit = $i64->constInt(\PHP_INT_MAX, false);

        $ht = HashTableHelper::alloc($context);
        $setString = $context->lookupFunction('__hashtable__setStringAt');

        $limitVal = $limit ?? $maxLimit;
        $limitSlot = $context->builder->alloca($i64, 1, 'explode_limit_'.$tag);
        $context->builder->store($limitVal, $limitSlot);

        $entryBlock = BasicBlockHelper::append($context, 'explode_entry_'.$tag);
        $singleBlock = BasicBlockHelper::append($context, 'explode_single_'.$tag);
        $loopHead = BasicBlockHelper::append($context, 'explode_head_'.$tag);
        $loopBody = BasicBlockHelper::append($context, 'explode_body_'.$tag);
        $limitDoneBlock = BasicBlockHelper::append($context, 'explode_limit_done_'.$tag);
        $continueBlock = BasicBlockHelper::append($context, 'explode_continue_check_'.$tag);
        $tailBlock = BasicBlockHelper::append($context, 'explode_tail_'.$tag);
        $appendEmptyBlock = BasicBlockHelper::append($context, 'explode_append_empty_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'explode_done_'.$tag);
        $context->builder->branch($entryBlock);

        $context->builder->positionAtEnd($entryBlock);
        if (null !== $limit) {
            $limitLeOne = $context->builder->icmp(Builder::INT_SLE, $limitVal, $one);
            $context->builder->branchIf($limitLeOne, $singleBlock, $loopHead);
        } else {
            $context->builder->branch($loopHead);
        }

        $context->builder->positionAtEnd($singleBlock);
        $context->builder->call($setString, $ht, $sizeT->constInt(0, false), $haystack);
        $context->builder->branch($doneBlock);

        $offsetSlot = $context->builder->alloca($i64, 1, 'explode_offset_'.$tag);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'explode_idx_'.$tag);
        $context->builder->store($zero, $offsetSlot);
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);

        $context->builder->positionAtEnd($loopHead);
        $offset = $context->builder->load($offsetSlot);
        $searchPtr = $context->builder->gep($hayPtr, $offset);
        $found = $context->builder->call(
            $context->lookupFunction('strstr'),
            $searchPtr,
            $delimPtr
        );
        $null = $context->getTypeFromString('int8*')->constNull();
        $notFound = $context->builder->icmp(Builder::INT_EQ, $found, $null);
        $context->builder->branchIf($notFound, $tailBlock, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $foundInt = $context->builder->ptrToInt($found, $i64);
        $baseInt = $context->builder->ptrToInt($hayPtr, $i64);
        $pos = $context->builder->sub($foundInt, $baseInt);
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

        $context->builder->positionAtEnd($doneBlock);

        BasicBlockHelper::branchToFreshContinue($context, 'explode_continue_'.$tag);

        return $ht;
    }
}
