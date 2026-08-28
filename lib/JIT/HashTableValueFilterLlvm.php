<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for array_diff() / array_intersect() (#27522 peer, ext/standard/array.c).
 *
 * Thin AOT NestedJIT of ArrayDiffJitHelper / ArrayIntersectJitHelper returned non-native
 * hashtables and segfaulted under standalone AOT (peer #27546 array_merge, #27522 array_diff_key).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmArray::diffTwo()} /
 * {@see \PHPCompiler\ext\standard\VmArray::intersectTwo()}
 * php-src: ext/standard/array.c — php_array_diff / php_array_intersect
 */
final class HashTableValueFilterLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /** Single-arg copy (replaceCopy shape). */
    public static function copy(Context $context, Value $srcHt): Value
    {
        return HashTableKeyFilterLlvm::copy($context, $srcHt);
    }

    /** Keep entries from $first whose loose value is absent from $other. */
    public static function diff(Context $context, Value $first, Value $other): Value
    {
        return self::filterByValueMembership($context, $first, $other, false);
    }

    /** Keep entries from $first whose loose value exists in $other. */
    public static function intersect(Context $context, Value $first, Value $other): Value
    {
        return self::filterByValueMembership($context, $first, $other, true);
    }

    private static function filterByValueMembership(
        Context $context,
        Value $first,
        Value $other,
        bool $keepWhenPresent
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
        $prefix = $keepWhenPresent ? 'ht_intersect' : 'ht_diff';

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

        $present = self::haystackContainsLooseEqual($context, $valVar, $other);
        $copy = BasicBlockHelper::append($context, $prefix.'_copy_'.$tag);
        $skip = BasicBlockHelper::append($context, $prefix.'_skip_'.$tag);
        if ($keepWhenPresent) {
            $context->builder->branchIf($present, $copy, $skip);
        } else {
            $context->builder->branchIf($present, $skip, $copy);
        }

        $context->builder->positionAtEnd($copy);
        HashTableWriteLlvm::setValueBoxKey($context, $dest, $keyVar, $valVar);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $dest;
    }

    private static function haystackContainsLooseEqual(
        Context $context,
        Variable $needle,
        Value $haystack
    ): Value {
        $pairs = Call\HashTableExportKeyValuePairs::exportPairsForSlice($context, $haystack);
        $tag = (string) self::nextSeq();
        $i1 = $context->getTypeFromString('int1');
        $result = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i1->constInt(0, false), $result);

        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'ht_valfind_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_valfind_body_'.$tag);
        $hit = BasicBlockHelper::append($context, 'ht_valfind_hit_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_valfind_adv_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_valfind_done_'.$tag);
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
        $candidate = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);
        $eq = JitValueCompare::looseEqualOperands($context, $needle, $candidate);
        $context->builder->branchIf($eq, $hit, $advance);

        $context->builder->positionAtEnd($hit);
        $context->builder->store($i1->constInt(1, false), $result);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($result);
    }
}
