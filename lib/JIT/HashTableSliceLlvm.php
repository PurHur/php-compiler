<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * NestedJIT LLVM for {@see \PHPCompiler\VM\HashTable::sliceCopy()} (#23974).
 *
 * Must not call {@see Builtin\ArraySliceRuntime} — that NestedJIT-compiles
 * {@see \PHPCompiler\ext\standard\ArraySliceJitHelper} and would recurse
 * (peer {@see HashTableCowLlvm} / #23548).
 *
 * php-src: ext/standard/array.c — php_array_slice()
 */
final class HashTableSliceLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /**
     * @param Value      $offset    i64
     * @param Value      $hasLength i1
     * @param Value      $length    i64
     * @param Value|null $preserve  i1 or null (false)
     */
    public static function slice(
        Context $context,
        Value $srcHt,
        Value $offset,
        Value $hasLength,
        Value $length,
        ?Value $preserve = null
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $preserveFlag = null === $preserve ? $i1->constInt(0, false) : $preserve;
        $pairs = Call\HashTableExportKeyValuePairs::exportPairsForSlice($context, $srcHt);

        return self::sliceFromPairs($context, $pairs, $offset, $hasLength, $length, $preserveFlag);
    }

    private static function sliceFromPairs(
        Context $context,
        Value $pairs,
        Value $offset,
        Value $hasLength,
        Value $length,
        Value $preserve
    ): Value {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $tag = (string) self::nextSeq();

        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );
        $numI64 = JitNestedHelperCoerce::scalarToI64($context, $num, $sizeT);

        $offSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($offset, $offSlot);
        $negOff = BasicBlockHelper::append($context, 'ht_slice_neg_off_'.$tag);
        $offReady = BasicBlockHelper::append($context, 'ht_slice_off_ready_'.$tag);
        $isNeg = $context->builder->icmp(Builder::INT_SLT, $offset, $i64->constInt(0, false));
        $context->builder->branchIf($isNeg, $negOff, $offReady);

        $context->builder->positionAtEnd($negOff);
        $adjusted = $context->builder->add($numI64, $offset);
        $stillNeg = $context->builder->icmp(Builder::INT_SLT, $adjusted, $i64->constInt(0, false));
        $clamped = $context->builder->select($stillNeg, $i64->constInt(0, false), $adjusted);
        $context->builder->store($clamped, $offSlot);
        $context->builder->branch($offReady);

        $context->builder->positionAtEnd($offReady);
        $normOff = $context->builder->load($offSlot);

        $takeSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $withLen = BasicBlockHelper::append($context, 'ht_slice_with_len_'.$tag);
        $noLen = BasicBlockHelper::append($context, 'ht_slice_no_len_'.$tag);
        $takeReady = BasicBlockHelper::append($context, 'ht_slice_take_ready_'.$tag);
        $context->builder->branchIf($hasLength, $withLen, $noLen);

        $context->builder->positionAtEnd($noLen);
        $context->builder->store($context->builder->sub($numI64, $normOff), $takeSlot);
        $context->builder->branch($takeReady);

        $context->builder->positionAtEnd($withLen);
        $lenNeg = BasicBlockHelper::append($context, 'ht_slice_len_neg_'.$tag);
        $lenPos = BasicBlockHelper::append($context, 'ht_slice_len_pos_'.$tag);
        $isLenNeg = $context->builder->icmp(Builder::INT_SLT, $length, $i64->constInt(0, false));
        $context->builder->branchIf($isLenNeg, $lenNeg, $lenPos);

        $context->builder->positionAtEnd($lenNeg);
        $context->builder->store(
            $context->builder->add($context->builder->sub($numI64, $normOff), $length),
            $takeSlot
        );
        $context->builder->branch($takeReady);

        $context->builder->positionAtEnd($lenPos);
        $context->builder->store($length, $takeSlot);
        $context->builder->branch($takeReady);

        $context->builder->positionAtEnd($takeReady);
        $takeRaw = $context->builder->load($takeSlot);
        $takeNeg = $context->builder->icmp(Builder::INT_SLT, $takeRaw, $i64->constInt(0, false));
        $takeNonNeg = $context->builder->select($takeNeg, $i64->constInt(0, false), $takeRaw);
        $remain = $context->builder->sub($numI64, $normOff);
        $offPast = $context->builder->icmp(Builder::INT_SGE, $normOff, $numI64);
        $takeClamped = $context->builder->select(
            $offPast,
            $i64->constInt(0, false),
            $context->builder->select(
                $context->builder->icmp(Builder::INT_SGT, $takeNonNeg, $remain),
                $remain,
                $takeNonNeg
            )
        );

        $dest = HashTableHelper::alloc($context);
        // Tracks int keys written under preserveKeys so grow-memset NULL holes can
        // be marked UNDEFINED (foreach skips UNDEFINED, not NULL — #27581).
        $written = HashTableHelper::alloc($context);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $outIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($zero, $outIdxSlot);

        $head = BasicBlockHelper::append($context, 'ht_slice_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_slice_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_slice_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $idxI64 = JitNestedHelperCoerce::scalarToI64($context, $idx, $sizeT);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $idxI64, $numI64);
        $context->builder->branchIf($pastEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $before = $context->builder->icmp(Builder::INT_SLT, $idxI64, $normOff);
        $outIdx = $context->builder->load($outIdxSlot);
        $outI64 = JitNestedHelperCoerce::scalarToI64($context, $outIdx, $sizeT);
        $enough = $context->builder->icmp(Builder::INT_SGE, $outI64, $takeClamped);
        $skip = BasicBlockHelper::append($context, 'ht_slice_skip_'.$tag);
        $copy = BasicBlockHelper::append($context, 'ht_slice_copy_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_slice_adv_'.$tag);
        $context->builder->branchIf($context->builder->or($before, $enough), $skip, $copy);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($copy);
        $pair = HashTableReadLlvm::readIndexedToValueBox($context, $pairs, $idx);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pair)
        );
        $keyVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $zero);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);

        $keepKeys = BasicBlockHelper::append($context, 'ht_slice_keep_'.$tag);
        $reindex = BasicBlockHelper::append($context, 'ht_slice_reidx_'.$tag);
        $context->builder->branchIf($preserve, $keepKeys, $reindex);

        $context->builder->positionAtEnd($keepKeys);
        self::writeKeyed($context, $dest, $written, $keyVar, $valVar);
        $context->builder->store($context->builder->addNoSignedWrap($outIdx, $one), $outIdxSlot);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($reindex);
        HashTableHelper::setAtIndex($context, $dest, $outIdx, $valVar);
        $context->builder->store($context->builder->addNoSignedWrap($outIdx, $one), $outIdxSlot);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        self::markUnwrittenNullHolesUndefined($context, $dest, $written, $preserve);

        return $dest;
    }

    /**
     * Grow memset zeroes new slots as TYPE_NULL; foreach treats NULL as a real
     * element. Mark slots that preserveKeys never wrote as TYPE_UNDEFINED (#27581).
     *
     * Public for RegexIterator / ParentIterator preserve-keys snapshots (#27313).
     */
    public static function markUnwrittenNullHolesUndefined(
        Context $context,
        Value $dest,
        Value $written,
        Value $preserve
    ): void {
        $tag = (string) self::nextSeq();
        $skip = BasicBlockHelper::append($context, 'ht_slice_hole_skip_'.$tag);
        $work = BasicBlockHelper::append($context, 'ht_slice_hole_work_'.$tag);
        $context->builder->branchIf($preserve, $work, $skip);

        $context->builder->positionAtEnd($work);
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $map = $context->structFieldMap['__hashtable__'];
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nfe = $context->builder->load($context->builder->structGep($dest, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'ht_slice_hole_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_slice_hole_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_slice_hole_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_UGE, $idx, $nfe);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $wasWritten = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $written,
            $idx
        );
        $advance = BasicBlockHelper::append($context, 'ht_slice_hole_adv_'.$tag);
        $maybeHole = BasicBlockHelper::append($context, 'ht_slice_hole_maybe_'.$tag);
        $context->builder->branchIf($wasWritten, $advance, $maybeHole);

        $context->builder->positionAtEnd($maybeHole);
        $values = $context->builder->load($context->builder->structGep($dest, $map['values']));
        $entry = $context->builder->inBoundsGep($values, $idx);
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $context->structFieldMap['__value__']['type'])
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $mark = BasicBlockHelper::append($context, 'ht_slice_hole_mark_'.$tag);
        $context->builder->branchIf($isNull, $mark, $advance);

        $context->builder->positionAtEnd($mark);
        // VM TYPE_UNDEFINED = -1 → uint8 255 (foreach hole skip).
        $context->builder->store(
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_UNDEFINED & 0xff, false),
            $context->builder->structGep($entry, $context->structFieldMap['__value__']['type'])
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->branch($skip);

        $context->builder->positionAtEnd($skip);
    }

    /** Preserve-keys write + int-key written-set for hole sealing (#27581 / #27313). */
    public static function writeKeyed(
        Context $context,
        Value $dest,
        Value $written,
        Variable $keyVar,
        Variable $valVar
    ): void {
        $keyPtr = JitValueBox::valuePtrFromVariable($context, $keyVar);
        $typeByte = $context->builder->load(
            $context->builder->structGep($keyPtr, $context->structFieldMap['__value__']['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $tag = (string) self::nextSeq();
        $strBb = BasicBlockHelper::append($context, 'ht_slice_key_str_'.$tag);
        $intBb = BasicBlockHelper::append($context, 'ht_slice_key_int_'.$tag);
        $join = BasicBlockHelper::append($context, 'ht_slice_key_join_'.$tag);
        $context->builder->branchIf($isString, $strBb, $intBb);

        $context->builder->positionAtEnd($strBb);
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        HashTableWriteLlvm::setAtStringKey($context, $dest, $str, $valVar);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($intBb);
        $long = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $idx = JitNestedHelperCoerce::i64ToScalar(
            $context,
            JitNestedHelperCoerce::scalarToI64($context, $long, $i64),
            $sizeT
        );
        HashTableHelper::setAtIndex($context, $dest, $idx, $valVar);
        // Record written int key (value=1) so hole-fix keeps intentional NULLs.
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $written,
            $idx,
            $i64->constInt(1, false)
        );
        $context->builder->branch($join);

        $context->builder->positionAtEnd($join);
    }
}
