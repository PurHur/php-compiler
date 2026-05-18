<?php

declare(strict_types=1);

/**
 * LLVM JIT/AOT helper for explode() — split a string by a non-empty delimiter.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitExplode
{
    private const NOT_FOUND = -1;

    public static function explode(Context $context, Value $delimiter, Value $string): Value
    {
        $map = $context->structFieldMap['__string__'];
        $delimLen = $context->builder->load(
            $context->builder->structGep($delimiter, $map['length'])
        );
        $hayLen = $context->builder->load(
            $context->builder->structGep($string, $map['length'])
        );
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $sizeT = $context->getTypeFromString('size_t');
        $oneSized = $sizeT->constInt(1, false);

        $ht = HashTableHelper::alloc($context);
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $charPtr = $context->builder->structGep($string, $map['value']);

        $offsetSlot = $context->builder->alloca($i64, 1, 'explode_offset');
        $indexSlot = $context->builder->alloca($sizeT, 1, 'explode_index');
        $context->builder->store($zero, $offsetSlot);
        $context->builder->store($sizeT->constInt(0, false), $indexSlot);

        $loopHead = BasicBlockHelper::append($context, 'explode_head');
        $loopBody = BasicBlockHelper::append($context, 'explode_body');
        $tailBlock = BasicBlockHelper::append($context, 'explode_tail');
        $doneBlock = BasicBlockHelper::append($context, 'explode_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $offset = $context->builder->load($offsetSlot);
        $pos = JitStrpos::find($context, $string, $delimiter, $offset);
        $notFoundVal = $i64->constInt(self::NOT_FOUND, false);
        $isNotFound = $context->builder->icmp(Builder::INT_EQ, $pos, $notFoundVal);
        $context->builder->branchIf($isNotFound, $tailBlock, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $partLen = $context->builder->sub($pos, $offset);
        $part = string_trim::jitCopySlice($context, $string, $charPtr, $offset, $partLen);
        $idx = $context->builder->load($indexSlot);
        $context->builder->call($setString, $ht, $idx, $part);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $oneSized), $indexSlot);
        $newOffset = $context->builder->addNoSignedWrap($pos, $delimLen);
        $pastEnd = $context->builder->icmp(Builder::INT_SGT, $newOffset, $hayLen);
        $context->builder->store($newOffset, $offsetSlot);
        $context->builder->branchIf($pastEnd, $tailBlock, $loopHead);

        $context->builder->positionAtEnd($tailBlock);
        $offset = $context->builder->load($offsetSlot);
        $partLen = $context->builder->sub($hayLen, $offset);
        $part = string_trim::jitCopySlice($context, $string, $charPtr, $offset, $partLen);
        $idx = $context->builder->load($indexSlot);
        $context->builder->call($setString, $ht, $idx, $part);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ht;
    }
}
