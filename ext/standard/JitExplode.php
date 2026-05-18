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
    public static function explode(Context $context, Value $delimiter, Value $haystack): Value
    {
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

        $ht = HashTableHelper::alloc($context);
        $setString = $context->lookupFunction('__hashtable__setStringAt');

        $offsetSlot = $context->builder->alloca($i64, 1, 'explode_offset');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'explode_idx');
        $context->builder->store($zero, $offsetSlot);
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);

        $loopHead = BasicBlockHelper::append($context, 'explode_head');
        $loopBody = BasicBlockHelper::append($context, 'explode_body');
        $tailBlock = BasicBlockHelper::append($context, 'explode_tail');
        $appendEmptyBlock = BasicBlockHelper::append($context, 'explode_append_empty');
        $doneBlock = BasicBlockHelper::append($context, 'explode_done');
        $context->builder->branch($loopHead);

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
        $pastEnd = $context->builder->icmp(Builder::INT_SGT, $newOffset, $hayLen);
        $context->builder->branchIf($pastEnd, $appendEmptyBlock, $loopHead);

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

        return $ht;
    }
}
