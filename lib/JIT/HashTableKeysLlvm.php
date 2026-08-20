<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * NestedJIT / call-site LLVM for {@see \PHPCompiler\VM\HashTable::keysCopy()} (#27211).
 *
 * Must not call {@see Builtin\ArrayKeysRuntime} — that NestedJIT-compiles
 * {@see \PHPCompiler\ext\standard\ArrayKeysJitHelper} and would recurse
 * (peer {@see HashTableReverseLlvm} / {@see HashTableCowLlvm}).
 *
 * Thin standalone AOT previously skipped NestedJIT keysCopy registration
 * (#20533 / #15417), so NestedJIT of ArrayKeysJitHelper returned an empty
 * hashtable — silent wrong output for array_keys().
 *
 * php-src: ext/standard/array.c — php_array_keys()
 */
final class HashTableKeysLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    public static function keys(Context $context, Value $srcHt): Value
    {
        $pairs = Call\HashTableExportKeyValuePairs::exportPairsForSlice($context, $srcHt);

        return self::keysFromPairs($context, $pairs);
    }

    private static function keysFromPairs(Context $context, Value $pairs): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $tag = (string) self::nextSeq();

        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );

        $dest = HashTableHelper::alloc($context);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $outIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($zero, $outIdxSlot);

        $head = BasicBlockHelper::append($context, 'ht_keys_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_keys_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_keys_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $pair = HashTableReadLlvm::readIndexedToValueBox($context, $pairs, $idx);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pair)
        );
        $outIdx = $context->builder->load($outIdxSlot);
        self::appendExportedPairKey($context, $dest, $outIdx, $pairHt, $zero);
        $context->builder->store($context->builder->addNoSignedWrap($outIdx, $one), $outIdxSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $dest;
    }

    /** Copy export pair key slot [0] without value-box re-separate (#26367). */
    private static function appendExportedPairKey(
        Context $context,
        Value $dest,
        Value $outIdx,
        Value $pairHt,
        Value $zero
    ): void {
        $keyEntry = HashTableHelper::listEntryPointer($context, $pairHt, $zero);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($keyEntry, $valueMap['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $tag = (string) self::nextSeq();
        $stringBlock = BasicBlockHelper::append($context, 'ht_keys_pair_str_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'ht_keys_pair_long_'.$tag);
        $fallback = BasicBlockHelper::append($context, 'ht_keys_pair_fb_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_keys_pair_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $afterString = BasicBlockHelper::append($context, 'ht_keys_pair_after_str_'.$tag);
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $keyEntry
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $dest,
            $outIdx,
            $str
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $fallback);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $outIdx,
            $context->builder->call($context->lookupFunction('__value__readLong'), $keyEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($fallback);
        $keyVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $zero);
        HashTableHelper::setAtIndex($context, $dest, $outIdx, $keyVar);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }
}
