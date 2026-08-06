<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmValueCompare;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * NestedJIT / call-site LLVM for {@see \PHPCompiler\VM\HashTable::keysMatchingCopy()} (#27544).
 *
 * Must not call {@see Builtin\ArrayKeysRuntime} — NestedJIT of
 * {@see \PHPCompiler\ext\standard\ArrayKeysJitHelper::keysMatching} would recurse
 * (peer {@see HashTableKeysLlvm} / {@see ArraySearchLlvm}).
 *
 * Thin AOT NestedJIT of the filtered helper segfaulted / returned empty; walk via
 * {@see Call\HashTableExportKeyValuePairs::exportPairsForSlice} (same normalize path as
 * {@see HashTableKeysLlvm}) and compare with {@see VmValueCompare::identicalValueToValue}
 * (peer {@see ArraySearchLlvm} / {@see InArrayLlvm} — same-type scalars; cross-type loose
 * coercion stays on the VM path).
 *
 * php-src: ext/standard/array.c — php_array_keys()
 */
final class HashTableKeysMatchingLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /**
     * @param Value $searchPtr boxed {@see __value__*} needle
     * @param Value $strict    i1 (kept for ABI parity; same-type compare uses identical)
     */
    public static function keysMatching(
        Context $context,
        Value $srcHt,
        Value $searchPtr,
        Value $strict
    ): Value {
        unset($strict);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ht_keys_matching_cont');
        $pairs = Call\HashTableExportKeyValuePairs::exportPairsForSlice($context, $srcHt);

        return self::keysMatchingFromPairs($context, $pairs, $searchPtr);
    }

    private static function keysMatchingFromPairs(
        Context $context,
        Value $pairs,
        Value $searchPtr
    ): Value {
        $sizeT = $context->getTypeFromString('size_t');
        $tag = (string) self::nextSeq();

        $searchVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            $searchPtr
        );

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

        $head = BasicBlockHelper::append($context, 'ht_keys_matching_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_keys_matching_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'ht_keys_matching_take_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_keys_matching_adv_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_keys_matching_done_'.$tag);
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
        $keyVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $zero);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);
        $match = VmValueCompare::identicalValueToValue($context, $searchVar, $valVar);
        $context->builder->branchIf($match, $take, $advance);

        $context->builder->positionAtEnd($take);
        $outIdx = $context->builder->load($outIdxSlot);
        HashTableHelper::setAtIndex($context, $dest, $outIdx, $keyVar);
        $context->builder->store($context->builder->addNoSignedWrap($outIdx, $one), $outIdxSlot);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $dest;
    }
}
