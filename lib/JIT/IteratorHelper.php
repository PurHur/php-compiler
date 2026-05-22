<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;

/**
 * LLVM helpers for foreach iterator opcodes (index stored in per-array alloca slots).
 */
final class IteratorHelper
{
    private static function asHashtable(Context $context, Variable $array): Variable
    {
        if (Variable::TYPE_HASHTABLE === $array->type) {
            return $array;
        }
        if (Variable::TYPE_VALUE === $array->type) {
            $valPtr = Variable::KIND_VARIABLE === $array->kind
                ? JitValueBox::pointer($context, $array->value)
                : $array->value;
            $ht = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $valPtr
            );

            return new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $ht
            );
        }
        throw new \LogicException(
            'foreach requires an array, got '.Variable::getStringType($array->type)
        );
    }

    private static function indexSlot(Context $context, Variable $array): \PHPLLVM\Value
    {
        $key = \spl_object_id($array);
        if (isset($context->foreachIndexSlots[$key])) {
            return $context->foreachIndexSlots[$key];
        }
        $sizeT = $context->getTypeFromString('size_t');
        $saved = $context->builder->getInsertBlock();
        if (null !== $context->main) {
            $blocks = $context->main->getBasicBlocks();
            if ([] !== $blocks) {
                $context->builder->positionAtEnd($blocks[0]);
            }
        }
        $slot = $context->builder->alloca($sizeT, 1, 'foreach_idx');
        $context->foreachIndexSlots[$key] = $slot;
        if (null !== $saved) {
            $context->builder->positionAtEnd($saved);
        }

        return $slot;
    }

    public static function compileReset(Context $context, Variable $array): void
    {
        $array = self::asHashtable($context, $array);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $invalid = $context->builder->sub($zero, $one);
        $context->builder->store($invalid, self::indexSlot($context, $array));
    }

    public static function compileValid(Context $context, Variable $array): \PHPLLVM\Value
    {
        $array = self::asHashtable($context, $array);
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $slot = self::indexSlot($context, $array);
        $idx = $context->builder->load($slot);
        $nextIdx = $context->builder->addNoSignedWrap($idx, $one);
        $context->builder->store($nextIdx, $slot);

        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $inPacked = $context->builder->icmp(Builder::INT_ULT, $nextIdx, $nextFree);
        $fn = $context->builder->getInsertBlock()->getParent();
        $packedBody = $fn->appendBasicBlock('foreach_packed_body');
        $strInit = $fn->appendBasicBlock('foreach_str_init');
        $strWalk = $fn->appendBasicBlock('foreach_str_walk');
        $found = $fn->appendBasicBlock('foreach_found');
        $empty = $fn->appendBasicBlock('foreach_empty');
        $merge = $fn->appendBasicBlock('foreach_valid_merge');
        $context->builder->branchIf($inPacked, $packedBody, $strInit);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $nextIdx
        );
        $packedBump = $fn->appendBasicBlock('foreach_packed_bump');
        $context->builder->branchIf($isSet, $found, $packedBump);
        $context->builder->positionAtEnd($packedBump);
        $context->builder->store($context->builder->addNoSignedWrap($nextIdx, $one), $slot);
        $context->builder->branch($packedBody);

        $context->builder->positionAtEnd($strInit);
        $context->builder->store($zero, $slot);
        $context->builder->branch($strWalk);

        $context->builder->positionAtEnd($strWalk);
        $ord = $context->builder->load($slot);
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $headNull = $context->builder->icmp(Builder::INT_EQ, $head, $head->typeOf()->constNull());
        $context->builder->branchIf($headNull, $empty, $strWalk);
        $node = $context->builder->phi($head->typeOf());
        $node->addIncoming($head, $strInit);
        $ordPhi = $context->builder->phi($sizeT);
        $ordPhi->addIncoming($ord, $strInit);
        $atTarget = $context->builder->icmp(Builder::INT_EQ, $ordPhi, $zero);
        $strStep = $fn->appendBasicBlock('foreach_str_step');
        $context->builder->branchIf($atTarget, $found, $strStep);
        $context->builder->positionAtEnd($strStep);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $nextNull = $context->builder->icmp(Builder::INT_EQ, $nextNode, $nextNode->typeOf()->constNull());
        $context->builder->branchIf($nextNull, $empty, $strWalk);
        $node->addIncoming($nextNode, $strStep);
        $ordPhi->addIncoming($context->builder->sub($ordPhi, $one), $strStep);
        $context->builder->branch($strWalk);

        $context->builder->positionAtEnd($found);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($empty);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $result = $context->builder->phi($i1);
        $result->addIncoming($i1->constInt(1, false), $found);
        $result->addIncoming($i1->constInt(0, false), $empty);

        return $result;
    }

    public static function compileKey(Context $context, Variable $array): Variable
    {
        $array = self::asHashtable($context, $array);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $idx = $context->builder->load(self::indexSlot($context, $array));
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $inPacked = $context->builder->icmp(Builder::INT_ULT, $idx, $nextFree);
        $fn = $context->builder->getInsertBlock()->getParent();
        $packed = $fn->appendBasicBlock('foreach_key_packed');
        $str = $fn->appendBasicBlock('foreach_key_str');
        $done = $fn->appendBasicBlock('foreach_key_done');
        $context->builder->branchIf($inPacked, $packed, $str);
        $context->builder->positionAtEnd($packed);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $destPtr,
            $context->builder->truncOrBitCast($idx, $context->getTypeFromString('int64'))
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($str);
        $node = self::stringKeyNodeAt($context, $ht, $map, $nodeMap, $array);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $destPtr,
            $keyStr
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    public static function compileValue(Context $context, Variable $array): Variable
    {
        $array = self::asHashtable($context, $array);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $ht = $context->helper->loadValue($array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $valueMap = $context->structFieldMap['__value__'];
        $idx = $context->builder->load(self::indexSlot($context, $array));
        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $inPacked = $context->builder->icmp(Builder::INT_ULT, $idx, $nextFree);
        $fn = $context->builder->getInsertBlock()->getParent();
        $packed = $fn->appendBasicBlock('foreach_val_packed');
        $str = $fn->appendBasicBlock('foreach_val_str');
        $done = $fn->appendBasicBlock('foreach_val_done');
        $context->builder->branchIf($inPacked, $packed, $str);
        $context->builder->positionAtEnd($packed);
        $values = $context->builder->load($context->builder->structGep($ht, $map['values']));
        $entry = $context->builder->inBoundsGep($values, $idx);
        self::copyValueEntryToBox($context, $destPtr, $entry, $valueMap, $fn);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($str);
        $node = self::stringKeyNodeAt($context, $ht, $map, $nodeMap, $array);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        self::copyValueEntryToBox($context, $destPtr, $valField, $valueMap, $fn);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    /**
     * @param array<string, int> $map
     * @param array<string, int> $nodeMap
     */
    private static function stringKeyNodeAt(
        Context $context,
        \PHPLLVM\Value $ht,
        array $map,
        array $nodeMap,
        Variable $array
    ): \PHPLLVM\Value {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $ord = $context->builder->load(self::indexSlot($context, $array));
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $block = $context->builder->getInsertBlock();
        $fn = $block->getParent();
        $walkHead = $fn->appendBasicBlock('foreach_node_head');
        $walkBody = $fn->appendBasicBlock('foreach_node_body');
        $walkDone = $fn->appendBasicBlock('foreach_node_done');
        $context->builder->branch($walkHead);
        $context->builder->positionAtEnd($walkHead);
        $node = $context->builder->phi($head->typeOf());
        $node->addIncoming($head, $block);
        $remaining = $context->builder->phi($sizeT);
        $remaining->addIncoming($ord, $block);
        $atTarget = $context->builder->icmp(Builder::INT_EQ, $remaining, $zero);
        $context->builder->branchIf($atTarget, $walkDone, $walkBody);
        $context->builder->positionAtEnd($walkBody);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $node->addIncoming($nextNode, $walkBody);
        $remaining->addIncoming($context->builder->sub($remaining, $one), $walkBody);
        $context->builder->branch($walkHead);
        $context->builder->positionAtEnd($walkDone);

        return $node;
    }

    /**
     * @param array<string, int> $valueMap
     */
    private static function copyValueEntryToBox(
        Context $context,
        \PHPLLVM\Value $destPtr,
        \PHPLLVM\Value $entry,
        array $valueMap,
        \PHPLLVM\LLVMAbstract\Value\Function_ $fn
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load($context->builder->structGep($entry, $valueMap['type']));
        $stringBlock = $fn->appendBasicBlock('foreach_copy_string');
        $longBlock = $fn->appendBasicBlock('foreach_copy_long');
        $merge = $fn->appendBasicBlock('foreach_copy_merge');
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $longBlock);
        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $entry);
        $str = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $context->builder->call($context->lookupFunction('__value__writeString'), $destPtr, $str);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $destPtr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
    }
}
