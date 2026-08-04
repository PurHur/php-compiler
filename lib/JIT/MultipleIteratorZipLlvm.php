<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * MultipleIterator attach zip — pure LLVM (thin AOT; NestedJIT HashTable bridges segfault).
 *
 * php-src: ext/spl/spl_iterators.c — MIT_NEED_ALL | MIT_KEYS_NUMERIC row arrays (#27584).
 */
final class MultipleIteratorZipLlvm
{
    private static int $seq = 0;

    /**
     * @param Value $existing prior zip HT (ignored when $isFirst is true)
     * @param Value $next     newly attached iterator snapshot HT
     * @param Value $isFirst  i64 1 on first attach, else 0
     */
    public static function zip(Context $context, Value $existing, Value $next, Value $isFirst): Value
    {
        $tag = (string) (++self::$seq);
        $i64 = $context->getTypeFromString('int64');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__hashtable__*'));
        $firstBb = BasicBlockHelper::append($context, 'mi_zip_first_'.$tag);
        $nextBb = BasicBlockHelper::append($context, 'mi_zip_next_'.$tag);
        $joinBb = BasicBlockHelper::append($context, 'mi_zip_join_'.$tag);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $isFirst, $i64->constInt(0, false)),
            $firstBb,
            $nextBb
        );

        $context->builder->positionAtEnd($firstBb);
        $context->builder->store(self::zipFirst($context, $next, $tag.'f'), $resultSlot);
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($nextBb);
        $context->builder->store(self::zipAppend($context, $existing, $next, $tag.'a'), $resultSlot);
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);

        return $context->builder->load($resultSlot);
    }

    private static function zipFirst(Context $context, Value $next, string $tag): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $values = HashTableValuesLlvm::values($context, $next);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $values
        );
        $out = HashTableHelper::alloc($context);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'mi_first_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'mi_first_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'mi_first_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $val = HashTableReadLlvm::readIndexedToValueBox($context, $values, $idx);
        $row = HashTableHelper::alloc($context);
        HashTableHelper::setAtIndex($context, $row, $zero, $val);
        $boxed = self::boxHashtable($context, $row);
        HashTableHelper::setAtIndex($context, $out, $idx, $boxed);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $out;
    }

    private static function zipAppend(Context $context, Value $existing, Value $next, string $tag): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $existing
        );
        $nextValues = HashTableValuesLlvm::values($context, $next);
        $out = HashTableHelper::alloc($context);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'mi_app_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'mi_app_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'mi_app_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $rowBox = HashTableReadLlvm::readIndexedToValueBox($context, $existing, $idx);
        $oldRow = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $rowBox)
        );
        $newRow = HashTableHelper::alloc($context);
        HashTableHelper::spreadInto(
            $context,
            new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $newRow),
            new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $oldRow)
        );
        $rowLen = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $newRow
        );
        $nextVal = HashTableReadLlvm::readIndexedToValueBox($context, $nextValues, $idx);
        HashTableHelper::setAtIndex($context, $newRow, $rowLen, $nextVal);
        $boxed = self::boxHashtable($context, $newRow);
        HashTableHelper::setAtIndex($context, $out, $idx, $boxed);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $out;
    }

    private static function boxHashtable(Context $context, Value $ht): Variable
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $slot),
            $ht
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }
}
