<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM for {@see \PHPCompiler\VM\HashTable::shiftFirst()} (#24025).
 *
 * Used by NestedJIT {@see Call\HashTableShiftFirst} and by {@see Builtin\ArrayShiftRuntime}
 * (NestedJIT of ArrayShiftJitHelper Variable returns segfault under thin standalone AOT —
 * peer ArrayMapLlvm / HashTableCowLlvm).
 *
 * php-src: ext/standard/array.c — php_array_shift() / zend_hash_shift
 */
final class HashTableShiftLlvm
{
    /**
     * Remove and return the first packed-list element as a {@see __value__*} box
     * (null-typed when empty). Mutates $ht in place.
     */
    public static function shiftFirst(Context $context, Value $ht): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ht_shift_llvm_cont');
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $n = $context->builder->call($context->lookupFunction('__hashtable__getNumElements'), $ht);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $zero);
        $emptyBb = BasicBlockHelper::append($context, 'ht_shift_llvm_empty');
        $shiftBb = BasicBlockHelper::append($context, 'ht_shift_llvm_do');
        $doneBb = BasicBlockHelper::append($context, 'ht_shift_llvm_ret');
        $context->builder->branchIf($isEmpty, $emptyBb, $shiftBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $resultPtr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($shiftBb);
        $first = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $zero);
        JitValueBox::copyFromPointer(
            $context,
            $resultSlot,
            JitValueBox::valuePtrFromVariable($context, $first)
        );

        $indexSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $indexSlot);
        $limit = $context->builder->subNoSignedWrap($n, $one);
        $shiftHead = BasicBlockHelper::append($context, 'ht_shift_llvm_head');
        $shiftBody = BasicBlockHelper::append($context, 'ht_shift_llvm_body');
        $shiftDone = BasicBlockHelper::append($context, 'ht_shift_llvm_done');
        $context->builder->branch($shiftHead);

        $context->builder->positionAtEnd($shiftHead);
        $idx = $context->builder->load($indexSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $limit);
        $context->builder->branchIf($past, $shiftDone, $shiftBody);

        $context->builder->positionAtEnd($shiftBody);
        $srcIdx = $context->builder->addNoSignedWrap($idx, $one);
        $srcVal = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $srcIdx);
        HashTableHelper::setAtIndex($context, $ht, $idx, $srcVal);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $indexSlot);
        $context->builder->branch($shiftHead);

        $context->builder->positionAtEnd($shiftDone);
        $lastEntry = HashTableReadLlvm::listEntryPointer($context, $ht, $limit);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $lastEntry
        );
        $context->builder->store($limit, $context->builder->structGep($ht, $map['nextFreeElement']));
        $context->builder->store($limit, $context->builder->structGep($ht, $map['numElements']));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $resultSlot;
    }
}
