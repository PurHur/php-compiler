<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site / NestedJIT LLVM for {@see \PHPCompiler\VM\HashTable::spliceInPlace()} (#27075).
 *
 * Port of pre-#17967 ArrayBuiltinHelper::buildSpliceFromHashTable packed path — avoids
 * NestedJIT of ArraySpliceJitHelper (HashTable::spliceInPlace / `spliceinplace` undefined)
 * and avoids exportPairs (thin-AOT hostile for this mutator).
 *
 * Must not call {@see Builtin\ArraySpliceRuntime} (recursion).
 * php-src: ext/standard/array.c — php_array_splice() packed path.
 */
final class HashTableSpliceLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /**
     * Mutate $srcHt in place; return the removed slice hashtable.
     *
     * @param Value $offset          i64
     * @param Value $hasLength       i1
     * @param Value $length          i64
     * @param Value $hasReplacement  i1
     * @param Value $replacementHt   __hashtable__* (ignored when !$hasReplacement)
     */
    public static function spliceInPlace(
        Context $context,
        Value $srcHt,
        Value $offset,
        Value $hasLength,
        Value $length,
        Value $hasReplacement,
        Value $replacementHt
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ht_splice_cont');
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $snapshot = self::clonePacked($context, $srcHt);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $srcHt
        );
        $numI64 = JitNestedHelperCoerce::scalarToI64($context, $num, $sizeT);
        $normOffI64 = self::normalizeOffset($context, $offset, $numI64);
        $normOff = JitNestedHelperCoerce::i64ToScalar($context, $normOffI64, $sizeT);
        $removeLen = self::computeRemoveLen($context, $num, $normOff, $hasLength, $length);

        $removed = HashTableHelper::alloc($context);
        self::copyPackedRange($context, $snapshot, $normOff, $removeLen, $removed, null);

        $replCount = $zero;
        $replBb = BasicBlockHelper::append($context, 'ht_splice_has_repl_'.self::nextSeq());
        $afterRepl = BasicBlockHelper::append($context, 'ht_splice_after_repl_'.self::nextSeq());
        $replCountSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $replCountSlot);
        $context->builder->branchIf($hasReplacement, $replBb, $afterRepl);

        $context->builder->positionAtEnd($replBb);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($replacementHt, $map['nextFreeElement'])),
            $replCountSlot
        );
        $context->builder->branch($afterRepl);

        $context->builder->positionAtEnd($afterRepl);
        $replCount = $context->builder->load($replCountSlot);

        $tailStart = $context->builder->add($normOff, $removeLen);
        $tailLen = $context->builder->sub($num, $tailStart);
        $newNum = $context->builder->add(
            $context->builder->add($normOff, $replCount),
            $tailLen
        );

        $temp = HashTableHelper::alloc($context);
        $destIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $destIdxSlot);

        self::copyPackedRange($context, $snapshot, $zero, $normOff, $temp, $destIdxSlot);

        $copyRepl = BasicBlockHelper::append($context, 'ht_splice_copy_repl_'.self::nextSeq());
        $afterCopyRepl = BasicBlockHelper::append($context, 'ht_splice_after_copy_repl_'.self::nextSeq());
        $context->builder->branchIf($hasReplacement, $copyRepl, $afterCopyRepl);

        $context->builder->positionAtEnd($copyRepl);
        self::copyPackedRange(
            $context,
            $replacementHt,
            $zero,
            $replCount,
            $temp,
            $destIdxSlot
        );
        $context->builder->branch($afterCopyRepl);

        $context->builder->positionAtEnd($afterCopyRepl);
        self::copyPackedRange($context, $snapshot, $tailStart, $tailLen, $temp, $destIdxSlot);

        self::copyTempOnto($context, $temp, $srcHt, $newNum);

        return $removed;
    }

    private static function normalizeOffset(Context $context, Value $offset, Value $numI64): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $tag = (string) self::nextSeq();
        $offSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($offset, $offSlot);
        $negOff = BasicBlockHelper::append($context, 'ht_splice_neg_off_'.$tag);
        $offReady = BasicBlockHelper::append($context, 'ht_splice_off_ready_'.$tag);
        $isNeg = $context->builder->icmp(Builder::INT_SLT, $offset, $i64->constInt(0, false));
        $context->builder->branchIf($isNeg, $negOff, $offReady);

        $context->builder->positionAtEnd($negOff);
        $adjusted = $context->builder->add($numI64, $offset);
        $stillNeg = $context->builder->icmp(Builder::INT_SLT, $adjusted, $i64->constInt(0, false));
        $clamped = $context->builder->select($stillNeg, $i64->constInt(0, false), $adjusted);
        $context->builder->store($clamped, $offSlot);
        $context->builder->branch($offReady);

        $context->builder->positionAtEnd($offReady);

        return $context->builder->load($offSlot);
    }

    private static function computeRemoveLen(
        Context $context,
        Value $num,
        Value $normOffset,
        Value $hasLength,
        Value $length
    ): Value {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $lengthSized = JitNestedHelperCoerce::i64ToScalar(
            $context,
            JitNestedHelperCoerce::scalarToI64($context, $length, $i64),
            $sizeT
        );
        $lengthI64 = JitNestedHelperCoerce::scalarToI64($context, $length, $i64);
        $defaultLen = $context->builder->sub($num, $normOffset);
        $negAdjLen = JitNestedHelperCoerce::i64ToScalar(
            $context,
            $context->builder->add(
                JitNestedHelperCoerce::scalarToI64($context, $defaultLen, $sizeT),
                $lengthI64
            ),
            $sizeT
        );
        $withLength = $context->builder->select($hasLength, $lengthSized, $defaultLen);
        $negLength = $context->builder->and(
            $hasLength,
            $context->builder->icmp(Builder::INT_SLT, $lengthI64, $i64->constInt(0, false))
        );
        $removeLen = $context->builder->select($negLength, $negAdjLen, $withLength);
        $isNegative = $context->builder->icmp(Builder::INT_SLT, $removeLen, $zero);
        $removeLen = $context->builder->select($isNegative, $zero, $removeLen);
        $offsetAtOrPastEnd = $context->builder->icmp(Builder::INT_SGE, $normOffset, $num);
        $maxRem = $context->builder->sub($num, $normOffset);
        $tooMuch = $context->builder->icmp(Builder::INT_SGT, $removeLen, $maxRem);
        $capped = $context->builder->select($tooMuch, $maxRem, $removeLen);

        return $context->builder->select($offsetAtOrPastEnd, $zero, $capped);
    }

    private static function clonePacked(Context $context, Value $src): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );
        $dest = HashTableHelper::alloc($context);
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $dest, $num);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_splice_clone_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_splice_clone_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_splice_clone_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $elem = HashTableReadLlvm::readIndexedToValueBox($context, $src, $idx);
        HashTableHelper::setAtIndex($context, $dest, $idx, $elem);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->store($num, $context->builder->structGep($dest, $map['numElements']));
        $context->builder->store($num, $context->builder->structGep($dest, $map['nextFreeElement']));

        return $dest;
    }

    /**
     * Copy $count packed entries from $src[$srcStart..] onto $dest.
     *
     * @param Value|null $destIdxSlot when null, write at index 0..count-1 and set dest lengths
     */
    private static function copyPackedRange(
        Context $context,
        Value $src,
        Value $srcStart,
        Value $count,
        Value $dest,
        ?Value $destIdxSlot
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $srcIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $takenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($srcStart, $srcIdxSlot);
        $context->builder->store($zero, $takenSlot);
        $ownDest = null === $destIdxSlot;
        if ($ownDest) {
            $destIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
            $context->builder->store($zero, $destIdxSlot);
        }
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_splice_cpr_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_splice_cpr_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_splice_cpr_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $taken = $context->builder->load($takenSlot);
        $atLimit = $context->builder->icmp(Builder::INT_SGE, $taken, $count);
        $context->builder->branchIf($atLimit, $done, $body);

        $context->builder->positionAtEnd($body);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $destIdx = $context->builder->load($destIdxSlot);
        $elem = HashTableReadLlvm::readIndexedToValueBox($context, $src, $srcIdx);
        HashTableHelper::setAtIndex($context, $dest, $destIdx, $elem);
        $context->builder->store($context->builder->addNoSignedWrap($destIdx, $one), $destIdxSlot);
        $context->builder->store($context->builder->addNoSignedWrap($srcIdx, $one), $srcIdxSlot);
        $context->builder->store($context->builder->addNoSignedWrap($taken, $one), $takenSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        if ($ownDest) {
            $context->builder->store($count, $context->builder->structGep($dest, $map['numElements']));
            $context->builder->store($count, $context->builder->structGep($dest, $map['nextFreeElement']));
        }
    }

    private static function copyTempOnto(Context $context, Value $temp, Value $dest, Value $newNum): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $dest, $newNum);
        $context->builder->store($newNum, $context->builder->structGep($dest, $map['numElements']));
        $context->builder->store($newNum, $context->builder->structGep($dest, $map['nextFreeElement']));

        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_splice_onto_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_splice_onto_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_splice_onto_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $newNum);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $elem = HashTableReadLlvm::readIndexedToValueBox($context, $temp, $idx);
        HashTableHelper::setAtIndex($context, $dest, $idx, $elem);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }
}
