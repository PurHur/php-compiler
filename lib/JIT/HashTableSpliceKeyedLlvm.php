<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Keyed / mixed array path for {@see HashTable::spliceInPlace()} (#27075 / #13573).
 *
 * SSOT: {@see \PHPCompiler\VM\HashTable::spliceKeyedInPlace()} · php-src ext/standard/array.c
 */
final class HashTableSpliceKeyedLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    public static function spliceInPlace(
        Context $context,
        Variable $array,
        Value $srcHt,
        Value $offset,
        Value $hasLength,
        Value $length,
        Value $hasReplacement,
        Value $replacementHt
    ): Value {
        $pairs = HashTableExportKeyValuePairs::exportPairsInForeachOrder($context, $srcHt);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $i1 = $context->getTypeFromString('int1');

        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );
        $numI64 = JitNestedHelperCoerce::scalarToI64($context, $num, $sizeT);
        $normOffI64 = HashTableSpliceLlvm::normalizeOffsetForSplice($context, $offset, $numI64);
        $normOff = JitNestedHelperCoerce::i64ToScalar($context, $normOffI64, $sizeT);
        $removeLen = HashTableSpliceLlvm::computeRemoveLenForSplice(
            $context,
            $num,
            $normOff,
            $hasLength,
            $length
        );

        $removedList = HashTableHelper::alloc($context);
        $removedIdx = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $removedIdx);
        self::appendPairRange(
            $context,
            $pairs,
            $normOff,
            $removeLen,
            $removedList,
            $removedIdx,
            $i64->constInt(0, false),
            $i1->constInt(0, false)
        );

        $newList = HashTableHelper::alloc($context);
        $newIdx = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $newIdx);
        self::appendPairRange(
            $context,
            $pairs,
            $zero,
            $normOff,
            $newList,
            $newIdx,
            $i64->constInt(0, false),
            $i1->constInt(0, false)
        );

        $replCountSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $replCountSlot);
        $replBb = BasicBlockHelper::append($context, 'ht_splice_key_repl_'.self::nextSeq());
        $afterRepl = BasicBlockHelper::append($context, 'ht_splice_key_after_repl_'.self::nextSeq());
        $context->builder->branchIf($hasReplacement, $replBb, $afterRepl);

        $context->builder->positionAtEnd($replBb);
        self::appendReplacementValues($context, $replacementHt, $newList, $newIdx, $replCountSlot);
        $context->builder->branch($afterRepl);

        $context->builder->positionAtEnd($afterRepl);
        $replCount = $context->builder->load($replCountSlot);
        $replCountI64 = JitNestedHelperCoerce::scalarToI64($context, $replCount, $sizeT);
        $tailStart = $context->builder->add($normOff, $removeLen);
        $tailLen = $context->builder->sub($num, $tailStart);
        self::appendPairRange(
            $context,
            $pairs,
            $tailStart,
            $tailLen,
            $newList,
            $newIdx,
            $replCountI64,
            $context->builder->icmp(Builder::INT_SGT, $replCount, $zero)
        );

        $dest = HashTableHelper::alloc($context);
        HashTableMutateNestedLlvm::assignPairsInForeachOrder($context, $dest, $newList);
        $removed = HashTableHelper::alloc($context);
        HashTableMutateNestedLlvm::assignPairsInForeachOrder($context, $removed, $removedList);

        HashTableHelper::storeHashtableInArrayVariable($context, $array, $dest);

        return $removed;
    }

    private static function appendPairRange(
        Context $context,
        Value $pairs,
        Value $start,
        Value $count,
        Value $outList,
        Value $outIdxSlot,
        Value $replCount,
        Value $renumberTailInts
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $tag = (string) self::nextSeq();

        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $takenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $nextIntSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($start, $idxSlot);
        $context->builder->store($zero, $takenSlot);
        $context->builder->store($replCount, $nextIntSlot);

        $head = BasicBlockHelper::append($context, 'ht_splice_kr_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_splice_kr_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_splice_kr_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $taken = $context->builder->load($takenSlot);
        $atLimit = $context->builder->icmp(Builder::INT_SGE, $taken, $count);
        $context->builder->branchIf($atLimit, $done, $body);

        $context->builder->positionAtEnd($body);
        $idx = $context->builder->load($idxSlot);
        [$keyVar, $valVar] = self::readPairKeyValue($context, $pairs, $idx);

        $keepKey = BasicBlockHelper::append($context, 'ht_splice_kr_keep_'.$tag);
        $rekey = BasicBlockHelper::append($context, 'ht_splice_kr_rekey_'.$tag);
        $writeJoin = BasicBlockHelper::append($context, 'ht_splice_kr_wjoin_'.$tag);
        $context->builder->branchIf($renumberTailInts, $rekey, $keepKey);

        $context->builder->positionAtEnd($keepKey);
        HashTableExportKeyValuePairs::appendPairToList($context, $outList, $outIdxSlot, $keyVar, $valVar);
        $context->builder->branch($writeJoin);

        $context->builder->positionAtEnd($rekey);
        $keyPtr = JitValueBox::valuePtrFromVariable($context, $keyVar);
        $typeByte = $context->builder->load(
            $context->builder->structGep($keyPtr, $context->structFieldMap['__value__']['type'])
        );
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $strKey = BasicBlockHelper::append($context, 'ht_splice_kr_str_'.$tag);
        $intKey = BasicBlockHelper::append($context, 'ht_splice_kr_int_'.$tag);
        $context->builder->branchIf($isString, $strKey, $intKey);

        $context->builder->positionAtEnd($strKey);
        HashTableExportKeyValuePairs::appendPairToList($context, $outList, $outIdxSlot, $keyVar, $valVar);
        $context->builder->branch($writeJoin);

        $context->builder->positionAtEnd($intKey);
        $nextInt = $context->builder->load($nextIntSlot);
        $newKey = self::longValueBox($context, $nextInt);
        HashTableExportKeyValuePairs::appendPairToList($context, $outList, $outIdxSlot, $newKey, $valVar);
        $context->builder->store(
            $context->builder->add($nextInt, $i64->constInt(1, false)),
            $nextIntSlot
        );
        $context->builder->branch($writeJoin);

        $context->builder->positionAtEnd($writeJoin);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->store($context->builder->addNoSignedWrap($taken, $one), $takenSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function appendReplacementValues(
        Context $context,
        Value $replacementHt,
        Value $outList,
        Value $outIdxSlot,
        Value $replCountSlot
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $tag = (string) self::nextSeq();

        $replPairs = HashTableExportKeyValuePairs::exportPairsInForeachOrder($context, $replacementHt);
        $replNum = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $replPairs
        );
        $context->builder->store($replNum, $replCountSlot);

        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $keySlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($i64->constInt(0, false), $keySlot);

        $head = BasicBlockHelper::append($context, 'ht_splice_krepl_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_splice_krepl_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_splice_krepl_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $replNum);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $pair = HashTableReadLlvm::readIndexedToValueBox($context, $replPairs, $idx);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pair)
        );
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);
        $intKey = $context->builder->load($keySlot);
        $keyVar = self::longValueBox($context, $intKey);
        HashTableExportKeyValuePairs::appendPairToList($context, $outList, $outIdxSlot, $keyVar, $valVar);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->store($context->builder->add($intKey, $i64->constInt(1, false)), $keySlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /** @return array{0: Variable, 1: Variable} */
    private static function readPairKeyValue(Context $context, Value $pairs, Value $idx): array
    {
        $zero = $context->getTypeFromString('size_t')->constInt(0, false);
        $one = $context->getTypeFromString('size_t')->constInt(1, false);
        $pair = HashTableReadLlvm::readIndexedToValueBox($context, $pairs, $idx);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pair)
        );
        $keyVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $zero);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);

        return [$keyVar, $valVar];
    }

    private static function longValueBox(Context $context, Value $long): Variable
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $long
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }
}
