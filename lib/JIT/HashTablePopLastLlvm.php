<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM for {@see \PHPCompiler\VM\HashTable::popLast()} (#27214).
 *
 * Used by NestedJIT {@see Call\HashTablePopLast} and by {@see Builtin\ArrayPopRuntime}
 * (peer of {@see HashTableShiftLlvm} — NestedJIT of ArrayPopJitHelper hit undefined
 * `poplast` / bridge ABI issues under thin standalone AOT).
 *
 * php-src: ext/standard/array.c — php_array_pop() / zend_hash_index_del last
 */
final class HashTablePopLastLlvm
{
    /**
     * Remove and return the last packed-list element as a {@see __value__*} box
     * (null-typed when empty). Mutates $ht in place.
     */
    public static function popLast(Context $context, Value $ht): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ht_pop_llvm_cont');
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $n = $context->builder->call($context->lookupFunction('__hashtable__getNumElements'), $ht);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $zero);
        $emptyBb = BasicBlockHelper::append($context, 'ht_pop_llvm_empty');
        $popBb = BasicBlockHelper::append($context, 'ht_pop_llvm_do');
        $doneBb = BasicBlockHelper::append($context, 'ht_pop_llvm_ret');
        $context->builder->branchIf($isEmpty, $emptyBb, $popBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $resultPtr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($popBb);
        $lastIdx = $context->builder->subNoSignedWrap($n, $one);
        $last = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $lastIdx);
        JitValueBox::copyFromPointer(
            $context,
            $resultSlot,
            JitValueBox::valuePtrFromVariable($context, $last)
        );
        $lastEntry = HashTableReadLlvm::listEntryPointer($context, $ht, $lastIdx);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $lastEntry
        );
        $context->builder->store($lastIdx, $context->builder->structGep($ht, $map['nextFreeElement']));
        $context->builder->store($lastIdx, $context->builder->structGep($ht, $map['numElements']));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $resultSlot;
    }
}
