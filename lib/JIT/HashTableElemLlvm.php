<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM for array_first()/array_last() under thin standalone AOT (#27596).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\ArrayElemJitHelper} Variable returns are
 * wrong/segfaulting under thin AOT (peer {@see HashTableShiftLlvm} / #24025). JIT/MCJIT
 * keeps the php-in-PHP helper via {@see Builtin\ArrayElemRuntime}.
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmArray::valueFirst()} / {@see valueLast()}
 * php-src: ext/standard/array.c — php_array_first, php_array_last
 */
final class HashTableElemLlvm
{
    public static function valueFirst(Context $context, Value $ht): Value
    {
        return self::valueAtEnd($context, $ht, true);
    }

    public static function valueLast(Context $context, Value $ht): Value
    {
        return self::valueAtEnd($context, $ht, false);
    }

    private static function valueAtEnd(Context $context, Value $ht, bool $first): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ht_elem_llvm_cont');
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);

        $emptyBb = BasicBlockHelper::append($context, 'array_elem_'.($first ? 'first' : 'last').'_empty');
        $workBb = BasicBlockHelper::append($context, 'array_elem_'.($first ? 'first' : 'last').'_work');
        $doneBb = BasicBlockHelper::append($context, 'array_elem_'.($first ? 'first' : 'last').'_done');
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $resultPtr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($workBb);
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $hasPacked = $context->builder->icmp(Builder::INT_NE, $nextFree, $zero);
        $packedBb = BasicBlockHelper::append($context, 'array_elem_'.($first ? 'first' : 'last').'_packed');
        $stringBb = BasicBlockHelper::append($context, 'array_elem_'.($first ? 'first' : 'last').'_string');
        $context->builder->branchIf($hasPacked, $packedBb, $stringBb);

        $tag = $first ? 'first' : 'last';
        $context->builder->positionAtEnd($packedBb);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_elem_'.$tag.'_idx');
        if ($first) {
            $context->builder->store($zero, $idxSlot);
        } else {
            $context->builder->store($context->builder->sub($nextFree, $one), $idxSlot);
        }
        $loopHead = BasicBlockHelper::append($context, 'array_elem_'.$tag.'_head');
        $loopBody = BasicBlockHelper::append($context, 'array_elem_'.$tag.'_body');
        $loopFound = BasicBlockHelper::append($context, 'array_elem_'.$tag.'_found');
        $loopNext = BasicBlockHelper::append($context, 'array_elem_'.$tag.'_next');
        $loopFail = BasicBlockHelper::append($context, 'array_elem_'.$tag.'_fail');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        if ($first) {
            $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
            $context->builder->branchIf($atEnd, $loopFail, $loopBody);
        } else {
            $atStart = $context->builder->icmp(Builder::INT_EQ, $idx, $zero);
            $context->builder->branchIf($atStart, $loopFail, $loopBody);
        }

        $context->builder->positionAtEnd($loopBody);
        $present = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($present, $loopFound, $loopNext);

        $context->builder->positionAtEnd($loopFound);
        $entryPtr = HashTableHelper::listEntryPointer($context, $ht, $idx);
        JitValueBox::copyFromPointer($context, $resultSlot, $entryPtr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($loopNext);
        if ($first) {
            $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        } else {
            $context->builder->store($context->builder->sub($idx, $one), $idxSlot);
        }
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopFail);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $resultPtr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($stringBb);
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        if ($first) {
            $headNull = $context->builder->icmp(Builder::INT_EQ, $head, $nodePtrType->constNull());
            $strEmpty = BasicBlockHelper::append($context, 'array_elem_first_str_empty');
            $strFound = BasicBlockHelper::append($context, 'array_elem_first_str_found');
            $context->builder->branchIf($headNull, $strEmpty, $strFound);
            $context->builder->positionAtEnd($strEmpty);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                $resultPtr
            );
            $context->builder->branch($doneBb);
            $context->builder->positionAtEnd($strFound);
            $valEntry = $context->builder->structGep($head, $nodeMap['value']);
            JitValueBox::copyFromPointer($context, $resultSlot, $valEntry);
            $context->builder->branch($doneBb);
        } else {
            $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_elem_last_walk');
            $lastSlot = $context->builder->alloca($nodePtrType, 1, 'array_elem_last_node');
            $context->builder->store($head, $walkSlot);
            $context->builder->store($nodePtrType->constNull(), $lastSlot);
            $walkHead = BasicBlockHelper::append($context, 'array_elem_last_walk_head');
            $walkBody = BasicBlockHelper::append($context, 'array_elem_last_walk_body');
            $walkDone = BasicBlockHelper::append($context, 'array_elem_last_walk_done');
            $context->builder->branch($walkHead);

            $context->builder->positionAtEnd($walkHead);
            $walkNode = $context->builder->load($walkSlot);
            $walkEnd = $context->builder->icmp(Builder::INT_EQ, $walkNode, $nodePtrType->constNull());
            $context->builder->branchIf($walkEnd, $walkDone, $walkBody);

            $context->builder->positionAtEnd($walkBody);
            $context->builder->store($walkNode, $lastSlot);
            $nextWalk = $context->builder->load($context->builder->structGep($walkNode, $nodeMap['next']));
            $context->builder->store($nextWalk, $walkSlot);
            $context->builder->branch($walkHead);

            $context->builder->positionAtEnd($walkDone);
            $lastNode = $context->builder->load($lastSlot);
            $lastNull = $context->builder->icmp(Builder::INT_EQ, $lastNode, $nodePtrType->constNull());
            $strEmpty = BasicBlockHelper::append($context, 'array_elem_last_str_empty');
            $strFound = BasicBlockHelper::append($context, 'array_elem_last_str_found');
            $context->builder->branchIf($lastNull, $strEmpty, $strFound);
            $context->builder->positionAtEnd($strEmpty);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                $resultPtr
            );
            $context->builder->branch($doneBb);
            $context->builder->positionAtEnd($strFound);
            $valEntry = $context->builder->structGep($lastNode, $nodeMap['value']);
            JitValueBox::copyFromPointer($context, $resultSlot, $valEntry);
            $context->builder->branch($doneBb);
        }

        $context->builder->positionAtEnd($doneBb);

        return $resultPtr;
    }
}
