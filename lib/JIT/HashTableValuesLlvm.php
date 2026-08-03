<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * NestedJIT / call-site LLVM for {@see \PHPCompiler\VM\HashTable::valuesCopy()} (#27212).
 *
 * Must not call {@see Builtin\ArrayValuesRuntime} — NestedJIT of
 * {@see \PHPCompiler\ext\standard\ArrayValuesJitHelper} would recurse
 * (peer {@see HashTableKeysLlvm} / {@see HashTableReverseLlvm}).
 *
 * php-src: ext/standard/array.c — php_array_values()
 */
final class HashTableValuesLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    public static function values(Context $context, Value $srcHt): Value
    {
        $pairs = Call\HashTableExportKeyValuePairs::exportPairsForSlice($context, $srcHt);

        return self::valuesFromPairs($context, $pairs);
    }

    private static function valuesFromPairs(Context $context, Value $pairs): Value
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

        $head = BasicBlockHelper::append($context, 'ht_values_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_values_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_values_done_'.$tag);
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
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);
        $outIdx = $context->builder->load($outIdxSlot);
        HashTableHelper::setAtIndex($context, $dest, $outIdx, $valVar);
        $context->builder->store($context->builder->addNoSignedWrap($outIdx, $one), $outIdxSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $dest;
    }
}
