<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * NestedJIT LLVM for {@see \PHPCompiler\VM\HashTable::chunkCopy()} (#27074).
 *
 * Must not call {@see Builtin\ArrayChunkRuntime} — that NestedJIT-compiles
 * {@see \PHPCompiler\ext\standard\ArrayChunkJitHelper} and would recurse
 * (peer {@see HashTableSliceLlvm} / {@see HashTableReverseLlvm} / {@see HashTableCowLlvm}).
 *
 * php-src: ext/standard/array.c — php_array_chunk()
 */
final class HashTableChunkLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /**
     * @param Value $size         i64 chunk length (already > 0 from builtin guard)
     * @param Value $preserveKeys i1
     */
    public static function chunk(Context $context, Value $srcHt, Value $size, Value $preserveKeys): Value
    {
        $tag = (string) self::nextSeq();
        $preserveBb = BasicBlockHelper::append($context, 'ht_chunk_preserve_'.$tag);
        $packedBb = BasicBlockHelper::append($context, 'ht_chunk_packed_'.$tag);
        $merge = BasicBlockHelper::append($context, 'ht_chunk_merge_'.$tag);
        $context->builder->branchIf($preserveKeys, $preserveBb, $packedBb);

        $context->builder->positionAtEnd($packedBb);
        $packedOut = self::chunkPacked($context, $srcHt, $size);
        $packedEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($preserveBb);
        $pairs = Call\HashTableExportKeyValuePairs::exportPairsForSlice($context, $srcHt);
        $preserveOut = self::chunkFromPairs($context, $pairs, $size, $context->getTypeFromString('int1')->constInt(1, false));
        $preserveEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $phi = $context->builder->phi($htPtr);
        $phi->addIncoming($packedOut, $packedEnd);
        $phi->addIncoming($preserveOut, $preserveEnd);

        return $phi;
    }

    /** Packed list path — read source indices directly (no pair export). */
    private static function chunkPacked(Context $context, Value $srcHt, Value $size): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $tag = (string) self::nextSeq();

        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $srcHt
        );
        $numI64 = JitNestedHelperCoerce::scalarToI64($context, $num, $sizeT);

        $out = HashTableHelper::alloc($context);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $outIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $countSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $chunkSlot = BasicBlockHelper::entryAlloca($context, $htPtr);
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($zero, $outIdxSlot);
        $context->builder->store($i64->constInt(0, false), $countSlot);
        $context->builder->store($htPtr->constNull(), $chunkSlot);

        $head = BasicBlockHelper::append($context, 'ht_chunk_p_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_chunk_p_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_chunk_p_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $idxI64 = JitNestedHelperCoerce::scalarToI64($context, $idx, $sizeT);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $idxI64, $numI64);
        $context->builder->branchIf($pastEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $count = $context->builder->load($countSlot);
        $needNew = $context->builder->icmp(Builder::INT_EQ, $count, $i64->constInt(0, false));
        $newChunk = BasicBlockHelper::append($context, 'ht_chunk_p_new_'.$tag);
        $haveChunk = BasicBlockHelper::append($context, 'ht_chunk_p_have_'.$tag);
        $context->builder->branchIf($needNew, $newChunk, $haveChunk);

        $context->builder->positionAtEnd($newChunk);
        $context->builder->store(HashTableHelper::alloc($context), $chunkSlot);
        $context->builder->branch($haveChunk);

        $context->builder->positionAtEnd($haveChunk);
        $chunk = $context->builder->load($chunkSlot);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $srcHt, $idx);
        $countSize = JitNestedHelperCoerce::i64ToScalar($context, $count, $sizeT);
        HashTableHelper::setAtIndex($context, $chunk, $countSize, $valVar);

        $nextCount = $context->builder->add($count, $i64->constInt(1, false));
        $context->builder->store($nextCount, $countSlot);
        $full = $context->builder->icmp(Builder::INT_SGE, $nextCount, $size);
        $flush = BasicBlockHelper::append($context, 'ht_chunk_p_flush_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_chunk_p_adv_'.$tag);
        $context->builder->branchIf($full, $flush, $advance);

        $context->builder->positionAtEnd($flush);
        self::appendChunk($context, $out, $outIdxSlot, $chunk);
        $context->builder->store($i64->constInt(0, false), $countSlot);
        $context->builder->store($htPtr->constNull(), $chunkSlot);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $tailCount = $context->builder->load($countSlot);
        $hasTail = $context->builder->icmp(Builder::INT_SGT, $tailCount, $i64->constInt(0, false));
        $tail = BasicBlockHelper::append($context, 'ht_chunk_p_tail_'.$tag);
        $ret = BasicBlockHelper::append($context, 'ht_chunk_p_ret_'.$tag);
        $context->builder->branchIf($hasTail, $tail, $ret);

        $context->builder->positionAtEnd($tail);
        self::appendChunk($context, $out, $outIdxSlot, $context->builder->load($chunkSlot));
        $context->builder->branch($ret);

        $context->builder->positionAtEnd($ret);

        return $out;
    }

    private static function appendChunk(Context $context, Value $out, Value $outIdxSlot, Value $chunk): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $outIdx = $context->builder->load($outIdxSlot);
        $chunkVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $chunk);
        HashTableHelper::setAtIndex($context, $out, $outIdx, $chunkVar);
        $context->builder->store(
            $context->builder->addNoSignedWrap($outIdx, $sizeT->constInt(1, false)),
            $outIdxSlot
        );
    }

    private static function chunkFromPairs(
        Context $context,
        Value $pairs,
        Value $size,
        Value $preserve
    ): Value {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $tag = (string) self::nextSeq();

        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );
        $numI64 = JitNestedHelperCoerce::scalarToI64($context, $num, $sizeT);

        $out = HashTableHelper::alloc($context);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $outIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $countSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $chunkSlot = BasicBlockHelper::entryAlloca($context, $htPtr);
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($zero, $outIdxSlot);
        $context->builder->store($i64->constInt(0, false), $countSlot);
        $context->builder->store($htPtr->constNull(), $chunkSlot);

        $head = BasicBlockHelper::append($context, 'ht_chunk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_chunk_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_chunk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $idxI64 = JitNestedHelperCoerce::scalarToI64($context, $idx, $sizeT);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $idxI64, $numI64);
        $context->builder->branchIf($pastEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $count = $context->builder->load($countSlot);
        $needNew = $context->builder->icmp(Builder::INT_EQ, $count, $i64->constInt(0, false));
        $newChunk = BasicBlockHelper::append($context, 'ht_chunk_new_'.$tag);
        $haveChunk = BasicBlockHelper::append($context, 'ht_chunk_have_'.$tag);
        $context->builder->branchIf($needNew, $newChunk, $haveChunk);

        $context->builder->positionAtEnd($newChunk);
        $context->builder->store(HashTableHelper::alloc($context), $chunkSlot);
        $context->builder->branch($haveChunk);

        $context->builder->positionAtEnd($haveChunk);
        $chunk = $context->builder->load($chunkSlot);
        $pair = HashTableReadLlvm::readIndexedToValueBox($context, $pairs, $idx);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pair)
        );
        $keyVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $zero);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);

        $keepKeys = BasicBlockHelper::append($context, 'ht_chunk_keep_'.$tag);
        $reindex = BasicBlockHelper::append($context, 'ht_chunk_reidx_'.$tag);
        $afterWrite = BasicBlockHelper::append($context, 'ht_chunk_after_write_'.$tag);
        $context->builder->branchIf($preserve, $keepKeys, $reindex);

        $context->builder->positionAtEnd($keepKeys);
        self::writeKeyed($context, $chunk, $keyVar, $valVar);
        $context->builder->branch($afterWrite);

        $context->builder->positionAtEnd($reindex);
        $countSize = JitNestedHelperCoerce::i64ToScalar($context, $count, $sizeT);
        HashTableHelper::setAtIndex($context, $chunk, $countSize, $valVar);
        $context->builder->branch($afterWrite);

        $context->builder->positionAtEnd($afterWrite);
        $nextCount = $context->builder->add($count, $i64->constInt(1, false));
        $context->builder->store($nextCount, $countSlot);
        $full = $context->builder->icmp(Builder::INT_SGE, $nextCount, $size);
        $flush = BasicBlockHelper::append($context, 'ht_chunk_flush_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_chunk_adv_'.$tag);
        $context->builder->branchIf($full, $flush, $advance);

        $context->builder->positionAtEnd($flush);
        self::appendChunk($context, $out, $outIdxSlot, $chunk);
        $context->builder->store($i64->constInt(0, false), $countSlot);
        $context->builder->store($htPtr->constNull(), $chunkSlot);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $tailCount = $context->builder->load($countSlot);
        $hasTail = $context->builder->icmp(Builder::INT_SGT, $tailCount, $i64->constInt(0, false));
        $tail = BasicBlockHelper::append($context, 'ht_chunk_tail_'.$tag);
        $ret = BasicBlockHelper::append($context, 'ht_chunk_ret_'.$tag);
        $context->builder->branchIf($hasTail, $tail, $ret);

        $context->builder->positionAtEnd($tail);
        self::appendChunk($context, $out, $outIdxSlot, $context->builder->load($chunkSlot));
        $context->builder->branch($ret);

        $context->builder->positionAtEnd($ret);

        return $out;
    }

    private static function writeKeyed(Context $context, Value $dest, Variable $keyVar, Variable $valVar): void
    {
        $keyPtr = JitValueBox::valuePtrFromVariable($context, $keyVar);
        $typeByte = $context->builder->load(
            $context->builder->structGep($keyPtr, $context->structFieldMap['__value__']['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $tag = (string) self::nextSeq();
        $strBb = BasicBlockHelper::append($context, 'ht_chunk_key_str_'.$tag);
        $intBb = BasicBlockHelper::append($context, 'ht_chunk_key_int_'.$tag);
        $join = BasicBlockHelper::append($context, 'ht_chunk_key_join_'.$tag);
        $context->builder->branchIf($isString, $strBb, $intBb);

        $context->builder->positionAtEnd($strBb);
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        HashTableWriteLlvm::setAtStringKey($context, $dest, $str, $valVar);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($intBb);
        $long = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $sizeT = $context->getTypeFromString('size_t');
        $idx = JitNestedHelperCoerce::i64ToScalar(
            $context,
            JitNestedHelperCoerce::scalarToI64($context, $long, $context->getTypeFromString('int64')),
            $sizeT
        );
        HashTableHelper::setAtIndex($context, $dest, $idx, $valVar);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($join);
    }
}
