<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for array_intersect_key() / array_diff_key() (#27521, #27522).
 *
 * Thin AOT NestedJIT of ArrayIntersectKeyJitHelper / ArrayDiffKeyJitHelper returned
 * empty hashtables (peer keysCopy skip — #27211 / #20533). Walk via
 * {@see Call\HashTableExportKeyValuePairs::exportPairsForSlice} (same normalize path as
 * {@see HashTableKeysLlvm}) so thin-AOT const-local `__value__` array boxes are readable.
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmArray::intersectKeyTwo()} /
 * {@see \PHPCompiler\ext\standard\VmArray::diffKeyTwo()}
 * php-src: ext/standard/array.c — php_array_intersect_key / php_array_diff_key
 */
final class HashTableKeyFilterLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /** Single-arg copy (replaceCopy shape). */
    public static function copy(Context $context, Value $srcHt): Value
    {
        return self::filterByKeyPresence($context, $srcHt, null, false);
    }

    /** Keep entries from $first whose keys exist in $other (values from $first). */
    public static function intersectKey(Context $context, Value $first, Value $other): Value
    {
        return self::filterByKeyPresence($context, $first, $other, false);
    }

    /** Keep entries from $first whose keys are absent from $other. */
    public static function diffKey(Context $context, Value $first, Value $other): Value
    {
        return self::filterByKeyPresence($context, $first, $other, true);
    }

    /**
     * @param Value|null $other null → copy all entries from $first (single-arg)
     */
    private static function filterByKeyPresence(
        Context $context,
        Value $first,
        ?Value $other,
        bool $keepWhenAbsent
    ): Value {
        $pairs = Call\HashTableExportKeyValuePairs::exportPairsForSlice($context, $first);
        $dest = HashTableHelper::alloc($context);

        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $tag = (string) self::nextSeq();
        $prefix = null === $other
            ? 'ht_keyfilter_copy'
            : ($keepWhenAbsent ? 'ht_diffkey' : 'ht_intersectkey');

        $head = BasicBlockHelper::append($context, $prefix.'_head_'.$tag);
        $body = BasicBlockHelper::append($context, $prefix.'_body_'.$tag);
        $advance = BasicBlockHelper::append($context, $prefix.'_advance_'.$tag);
        $done = BasicBlockHelper::append($context, $prefix.'_done_'.$tag);
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

        if (null === $other) {
            self::storeKeyed($context, $dest, $keyVar, $valVar);
            $context->builder->branch($advance);
        } else {
            $otherHas = HashTableReadLlvm::offsetIsSetDim($context, $other, $keyVar);
            $copy = BasicBlockHelper::append($context, $prefix.'_copy_'.$tag);
            $skip = BasicBlockHelper::append($context, $prefix.'_skip_'.$tag);
            if ($keepWhenAbsent) {
                $context->builder->branchIf($otherHas, $skip, $copy);
            } else {
                $context->builder->branchIf($otherHas, $copy, $skip);
            }

            $context->builder->positionAtEnd($copy);
            self::storeKeyed($context, $dest, $keyVar, $valVar);
            $context->builder->branch($advance);

            $context->builder->positionAtEnd($skip);
            $context->builder->branch($advance);
        }

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $dest;
    }

    private static function storeKeyed(
        Context $context,
        Value $dest,
        Variable $keyVar,
        Variable $valVar
    ): void {
        HashTableWriteLlvm::setValueBoxKey($context, $dest, $keyVar, $valVar);
    }
}
