<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * HashTable::shiftFirst() for nested php-in-PHP JIT helpers (#24025).
 *
 * Pure LLVM — must not call ArrayShiftRuntime (NestedJIT of ArrayShiftJitHelper
 * would recurse; peer of {@see HashTableUnshiftPrepend} / #23974 sliceCopy).
 *
 * Returns a {@see __value__*} slot (null-typed when empty) so NestedJIT's
 * Variable-return ABI (#16565) can forward it from ArrayShiftJitHelper without
 * re-wrapping through {@see Variable} objects (#24025).
 */
final class HashTableShiftFirst implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('shiftFirst() requires a HashTable receiver');
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ht_shift_first_cont');
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $num = $context->builder->call($context->lookupFunction('__hashtable__getNumElements'), $ht);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);

        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);

        $emptyBb = BasicBlockHelper::append($context, 'ht_shift_first_empty');
        $workBb = BasicBlockHelper::append($context, 'ht_shift_first_work');
        $doneBb = BasicBlockHelper::append($context, 'ht_shift_first_done');
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $resultPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($workBb);
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $hasPacked = $context->builder->icmp(Builder::INT_NE, $nextFree, $zero);
        $packedBb = BasicBlockHelper::append($context, 'ht_shift_first_packed');
        $stringBb = BasicBlockHelper::append($context, 'ht_shift_first_string');
        $context->builder->branchIf($hasPacked, $packedBb, $stringBb);

        $context->builder->positionAtEnd($packedBb);
        $firstEntry = HashTableHelper::listEntryPointer($context, $ht, $zero);
        JitValueBox::copyIntoPointer($context, $resultPtr, $firstEntry);

        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $loopHead = BasicBlockHelper::append($context, 'ht_shift_first_head');
        $loopBody = BasicBlockHelper::append($context, 'ht_shift_first_body');
        $afterLoop = BasicBlockHelper::append($context, 'ht_shift_first_after');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $lastIndex = $context->builder->sub($num, $one);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $lastIndex);
        $context->builder->branchIf($atEnd, $afterLoop, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $nextIdx = $context->builder->addNoSignedWrap($idx, $one);
        JitValueBox::copyIntoPointer(
            $context,
            HashTableHelper::listEntryPointer($context, $ht, $idx),
            HashTableHelper::listEntryPointer($context, $ht, $nextIdx)
        );
        $context->builder->store($nextIdx, $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($afterLoop);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            HashTableHelper::listEntryPointer($context, $ht, $lastIndex)
        );
        $numPtr = $context->builder->structGep($ht, $map['numElements']);
        $nextFreePtr = $context->builder->structGep($ht, $map['nextFreeElement']);
        $context->builder->store($context->builder->sub($context->builder->load($numPtr), $one), $numPtr);
        $context->builder->store($context->builder->sub($context->builder->load($nextFreePtr), $one), $nextFreePtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($stringBb);
        $headPtr = $context->builder->structGep($ht, $map['strKeys']);
        $head = $context->builder->load($headPtr);
        $valField = $context->builder->structGep($head, $nodeMap['value']);
        JitValueBox::copyIntoPointer($context, $resultPtr, $valField);
        $nextNode = $context->builder->load($context->builder->structGep($head, $nodeMap['next']));
        $context->builder->store($nextNode, $headPtr);
        $numPtrStr = $context->builder->structGep($ht, $map['numElements']);
        $context->builder->store(
            $context->builder->sub($context->builder->load($numPtrStr), $one),
            $numPtrStr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        // Match HashTableFindIndex: return __value__* for NestedJIT Variable ABI.
        return $resultPtr;
    }
}
