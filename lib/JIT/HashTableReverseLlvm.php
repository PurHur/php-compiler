<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * NestedJIT / call-site LLVM for {@see \PHPCompiler\VM\HashTable::reverseCopy()} (#27067).
 *
 * Must not call {@see Builtin\ArrayReverseRuntime} — that NestedJIT-compiles
 * {@see \PHPCompiler\ext\standard\ArrayReverseJitHelper} and would recurse
 * (peer {@see HashTableSliceLlvm} / {@see HashTableCowLlvm}).
 *
 * php-src: ext/standard/array.c — php_array_reverse()
 * String keys are always preserved; int keys re-index unless $preserve_keys.
 */
final class HashTableReverseLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /** @param Value $preserveKeys i1 */
    public static function reverse(Context $context, Value $srcHt, Value $preserveKeys): Value
    {
        $pairs = Call\HashTableExportKeyValuePairs::exportPairsForSlice($context, $srcHt);

        return self::reverseFromPairs($context, $pairs, $preserveKeys);
    }

    private static function reverseFromPairs(Context $context, Value $pairs, Value $preserve): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $tag = (string) self::nextSeq();

        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );
        $numI64 = JitNestedHelperCoerce::scalarToI64($context, $num, $sizeT);

        $dest = HashTableHelper::alloc($context);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $outIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $context->builder->store($context->builder->sub($numI64, $i64->constInt(1, false)), $idxSlot);
        $context->builder->store($zero, $outIdxSlot);

        $head = BasicBlockHelper::append($context, 'ht_rev_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_rev_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_rev_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $pastStart = $context->builder->icmp(Builder::INT_SLT, $idx, $i64->constInt(0, false));
        $context->builder->branchIf($pastStart, $done, $body);

        $context->builder->positionAtEnd($body);
        $idxSize = JitNestedHelperCoerce::i64ToScalar($context, $idx, $sizeT);
        $pair = HashTableReadLlvm::readIndexedToValueBox($context, $pairs, $idxSize);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pair)
        );
        $keyVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $zero);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);

        self::writeReversedEntry($context, $dest, $keyVar, $valVar, $preserve, $outIdxSlot);

        $context->builder->store($context->builder->sub($idx, $i64->constInt(1, false)), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $dest;
    }

    private static function writeReversedEntry(
        Context $context,
        Value $dest,
        Variable $keyVar,
        Variable $valVar,
        Value $preserve,
        Value $outIdxSlot
    ): void {
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
        $strBb = BasicBlockHelper::append($context, 'ht_rev_key_str_'.$tag);
        $intBb = BasicBlockHelper::append($context, 'ht_rev_key_int_'.$tag);
        $join = BasicBlockHelper::append($context, 'ht_rev_key_join_'.$tag);
        $context->builder->branchIf($isString, $strBb, $intBb);

        $context->builder->positionAtEnd($strBb);
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        HashTableWriteLlvm::setAtStringKey($context, $dest, $str, $valVar);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($intBb);
        $keep = BasicBlockHelper::append($context, 'ht_rev_keep_'.$tag);
        $reindex = BasicBlockHelper::append($context, 'ht_rev_reidx_'.$tag);
        $context->builder->branchIf($preserve, $keep, $reindex);

        $context->builder->positionAtEnd($keep);
        $long = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $sizeT = $context->getTypeFromString('size_t');
        $idx = JitNestedHelperCoerce::i64ToScalar(
            $context,
            JitNestedHelperCoerce::scalarToI64($context, $long, $context->getTypeFromString('int64')),
            $sizeT
        );
        HashTableHelper::setAtIndex($context, $dest, $idx, $valVar);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($reindex);
        $outIdx = $context->builder->load($outIdxSlot);
        HashTableHelper::setAtIndex($context, $dest, $outIdx, $valVar);
        $one = $sizeT->constInt(1, false);
        $context->builder->store($context->builder->addNoSignedWrap($outIdx, $one), $outIdxSlot);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($join);
    }
}
