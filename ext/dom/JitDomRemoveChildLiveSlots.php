<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT LLVM slot sync for DOMNode::removeChild() (#32774, re-#27475).
 *
 * Peer {@see JitDomReplaceChildLiveSlots} / {@see JitDomAppendChildLiveSlots}:
 * unlink the child from the sibling chain, then **decrement length in place** on
 * the existing childNodes list so held `$list = $parent->childNodes` observes the
 * update (php-src ext/dom/nodelist.c live collection). Replacing the list with a
 * fresh length-0 object left held lists stale and refetch at 0 while siblings remain.
 * Attr child: Not Found before sibling unlink (#33596 / peer #33587).
 *
 * Reference: php-src ext/dom/node.c dom_node_remove_child.
 */
final class JitDomRemoveChildLiveSlots
{
    public static function sync(Context $context, Value $parent, Value $child): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rm_live_slots');
        self::ensureLayout($context);

        // php-src: Attr is not content — Not Found before sibling slot walks (#33596).
        $bbAttr = BasicBlockHelper::append($context, 'dom_rm_ls_attr');
        $bbNotAttr = BasicBlockHelper::append($context, 'dom_rm_ls_not_attr');
        $isAttr = JitDomAppendChildLiveSlots::isAttrNode($context, $child);
        $context->builder->branchIf($isAttr, $bbAttr, $bbNotAttr);

        $context->builder->positionAtEnd($bbAttr);
        \PHPCompiler\JIT\TryCatchHelper::emitCatchableClassError(
            $context,
            'DOMException',
            'Not Found Error',
            null,
            '',
            0,
            DomExceptionConstants::NOT_FOUND_ERR
        );

        $context->builder->positionAtEnd($bbNotAttr);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $nullBox = self::nullValueVar($context);

        $prev = self::loadSibling($context, $child, VmDom::PROP_PREVIOUS_SIBLING, 'dom_rm_prev');
        $next = self::loadSibling($context, $child, VmDom::PROP_NEXT_SIBLING, 'dom_rm_next');
        $first = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_rm_first');
        $last = self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, 'dom_rm_last');

        // prev.next = next
        $bbPrevLink = BasicBlockHelper::append($context, 'dom_rm_prev_link');
        $bbAfterPrev = BasicBlockHelper::append($context, 'dom_rm_after_prev');
        $prevNull = $context->builder->icmp(Builder::INT_EQ, $prev, $objPtrTy->constNull());
        $context->builder->branchIf($prevNull, $bbAfterPrev, $bbPrevLink);
        $context->builder->positionAtEnd($bbPrevLink);
        self::storeSibling($context, $prev, VmDom::PROP_NEXT_SIBLING, self::objectOrNullVar($context, $next));
        $context->builder->branch($bbAfterPrev);

        // next.prev = prev
        $context->builder->positionAtEnd($bbAfterPrev);
        $bbNextLink = BasicBlockHelper::append($context, 'dom_rm_next_link');
        $bbAfterNext = BasicBlockHelper::append($context, 'dom_rm_after_next');
        $nextNull = $context->builder->icmp(Builder::INT_EQ, $next, $objPtrTy->constNull());
        $context->builder->branchIf($nextNull, $bbAfterNext, $bbNextLink);
        $context->builder->positionAtEnd($bbNextLink);
        self::storeSibling($context, $next, VmDom::PROP_PREVIOUS_SIBLING, self::objectOrNullVar($context, $prev));
        $context->builder->branch($bbAfterNext);

        // firstChild ← next when removing the first
        $context->builder->positionAtEnd($bbAfterNext);
        $bbSetFirst = BasicBlockHelper::append($context, 'dom_rm_set_first');
        $bbAfterFirst = BasicBlockHelper::append($context, 'dom_rm_after_first');
        $firstIsChild = $context->builder->icmp(Builder::INT_EQ, $first, $child);
        $context->builder->branchIf($firstIsChild, $bbSetFirst, $bbAfterFirst);
        $context->builder->positionAtEnd($bbSetFirst);
        self::storeChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, self::objectOrNullVar($context, $next));
        $context->builder->branch($bbAfterFirst);

        // lastChild ← prev when removing the last
        $context->builder->positionAtEnd($bbAfterFirst);
        $bbSetLast = BasicBlockHelper::append($context, 'dom_rm_set_last');
        $bbAfterLast = BasicBlockHelper::append($context, 'dom_rm_after_last');
        $lastIsChild = $context->builder->icmp(Builder::INT_EQ, $last, $child);
        $context->builder->branchIf($lastIsChild, $bbSetLast, $bbAfterLast);
        $context->builder->positionAtEnd($bbSetLast);
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, self::objectOrNullVar($context, $prev));
        $context->builder->branch($bbAfterLast);

        // Detach child — null parent/siblings on DOMElement layout (#28672 / #27411).
        $context->builder->positionAtEnd($bbAfterLast);
        self::storeSibling($context, $child, VmDom::PROP_PREVIOUS_SIBLING, $nullBox);
        self::storeSibling($context, $child, VmDom::PROP_NEXT_SIBLING, $nullBox);
        self::storeSibling($context, $child, VmDom::PROP_PARENT_NODE, $nullBox);

        $newFirst = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_rm_nfirst');
        // Do not loadSibling(null) — only-child remove leaves firstChild null (#27475).
        $bbSecondNull = BasicBlockHelper::append($context, 'dom_rm_second_null');
        $bbSecondRead = BasicBlockHelper::append($context, 'dom_rm_second_read');
        $bbSecondMerge = BasicBlockHelper::append($context, 'dom_rm_second_merge');
        $firstNull = $context->builder->icmp(Builder::INT_EQ, $newFirst, $objPtrTy->constNull());
        $context->builder->branchIf($firstNull, $bbSecondNull, $bbSecondRead);
        $context->builder->positionAtEnd($bbSecondNull);
        $nullPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbSecondMerge);
        $context->builder->positionAtEnd($bbSecondRead);
        $loadedSecond = self::loadSibling($context, $newFirst, VmDom::PROP_NEXT_SIBLING, 'dom_rm_nsecond');
        $readPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbSecondMerge);
        $context->builder->positionAtEnd($bbSecondMerge);
        $newSecond = $context->builder->phi($objPtrTy);
        $newSecond->addIncoming($objPtrTy->constNull(), $nullPred);
        $newSecond->addIncoming($loadedSecond, $readPred);
        self::decrementChildNodesLengthInPlace($context, $parent, $newFirst, $newSecond);
    }

    /**
     * Decrement an existing childNodes list by 1 (or seed length after unlink)
     * without replacing the list object — held `$list` must observe the update
     * (#32774 / #29048 peer, php-src nodelist.c).
     */
    private static function decrementChildNodesLengthInPlace(
        Context $context,
        Value $owner,
        Value $newFirst,
        Value $newSecond
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rm_dec_len');
        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $nullBox = self::nullValueVar($context);

        $existing = self::loadChildNodesListObject($context, $owner);
        $missing = $context->builder->icmp(Builder::INT_EQ, $existing, $objPtrTy->constNull());
        $bbSeed = BasicBlockHelper::append($context, 'dom_rm_dec_seed');
        $bbBump = BasicBlockHelper::append($context, 'dom_rm_dec_bump');
        $bbDone = BasicBlockHelper::append($context, 'dom_rm_dec_done');
        $context->builder->branchIf($missing, $bbSeed, $bbBump);

        $context->builder->positionAtEnd($bbSeed);
        // No prior list — seed from remaining edges (0 / 1 / 2+ → length 2 pin).
        $firstNull = $context->builder->icmp(Builder::INT_EQ, $newFirst, $objPtrTy->constNull());
        $bbEmpty = BasicBlockHelper::append($context, 'dom_rm_dec_seed_empty');
        $bbSome = BasicBlockHelper::append($context, 'dom_rm_dec_seed_some');
        $context->builder->branchIf($firstNull, $bbEmpty, $bbSome);
        $context->builder->positionAtEnd($bbEmpty);
        self::writeChildNodesList($context, $owner, 0, null, null);
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbSome);
        $secondNull = $context->builder->icmp(Builder::INT_EQ, $newSecond, $objPtrTy->constNull());
        $bbOne = BasicBlockHelper::append($context, 'dom_rm_dec_seed_one');
        $bbTwo = BasicBlockHelper::append($context, 'dom_rm_dec_seed_two');
        $context->builder->branchIf($secondNull, $bbOne, $bbTwo);
        $context->builder->positionAtEnd($bbOne);
        self::writeChildNodesList($context, $owner, 1, $newFirst, null);
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbTwo);
        self::writeChildNodesList($context, $owner, 2, $newFirst, $newSecond);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbBump);
        $lengthVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $existing,
            'DOMNodeList',
            'length',
            $listClassId
        );
        $current = $context->helper->loadValue($lengthVar);
        $one = $i64->constInt(1, false);
        $zero = $i64->constInt(0, false);
        $gt = $context->builder->icmp(Builder::INT_SGT, $current, $zero);
        $bbSub = BasicBlockHelper::append($context, 'dom_rm_dec_sub');
        $bbZero = BasicBlockHelper::append($context, 'dom_rm_dec_zero');
        $bbAfterLen = BasicBlockHelper::append($context, 'dom_rm_dec_after_len');
        $context->builder->branchIf($gt, $bbSub, $bbZero);
        $context->builder->positionAtEnd($bbSub);
        $next = $context->builder->sub($current, $one);
        $subPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbAfterLen);
        $context->builder->positionAtEnd($bbZero);
        $zeroPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbAfterLen);
        $context->builder->positionAtEnd($bbAfterLen);
        $phiLen = $context->builder->phi($i64);
        $phiLen->addIncoming($next, $subPred);
        $phiLen->addIncoming($zero, $zeroPred);
        $nextJit = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $phiLen);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', 'length'),
            $nextJit,
            JITVariable::TYPE_NATIVE_LONG
        );
        $ownerJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $owner);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $ownerJit,
            JITVariable::TYPE_VALUE
        );

        // Refresh pins from the new head — stale __phpcItemN beats owner walk in item().
        $firstNull2 = $context->builder->icmp(Builder::INT_EQ, $newFirst, $objPtrTy->constNull());
        $bbClearPins = BasicBlockHelper::append($context, 'dom_rm_dec_clear_pins');
        $bbSetPins = BasicBlockHelper::append($context, 'dom_rm_dec_set_pins');
        $bbPinsDone = BasicBlockHelper::append($context, 'dom_rm_dec_pins_done');
        $context->builder->branchIf($firstNull2, $bbClearPins, $bbSetPins);

        $context->builder->positionAtEnd($bbClearPins);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem0'),
            $nullBox,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem1'),
            $nullBox,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbPinsDone);

        $context->builder->positionAtEnd($bbSetPins);
        $i0 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newFirst);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem0'),
            $i0,
            JITVariable::TYPE_VALUE
        );
        $secondNull2 = $context->builder->icmp(Builder::INT_EQ, $newSecond, $objPtrTy->constNull());
        $bbPin1Null = BasicBlockHelper::append($context, 'dom_rm_dec_pin1_null');
        $bbPin1Set = BasicBlockHelper::append($context, 'dom_rm_dec_pin1_set');
        $context->builder->branchIf($secondNull2, $bbPin1Null, $bbPin1Set);
        $context->builder->positionAtEnd($bbPin1Null);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem1'),
            $nullBox,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbPinsDone);
        $context->builder->positionAtEnd($bbPin1Set);
        $i1 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newSecond);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem1'),
            $i1,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbPinsDone);

        $context->builder->positionAtEnd($bbPinsDone);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    private static function ensureLayout(Context $context): void
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        $listClassId = $objectType->lookup('DOMNodeList');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD, VmDom::PROP_CHILD_NODES] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        foreach ([
            VmDom::PROP_NEXT_SIBLING,
            VmDom::PROP_PREVIOUS_SIBLING,
            VmDom::PROP_PARENT_NODE,
        ] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        if (!$objectType->hasProperty($listClassId, 'length')) {
            $objectType->defineProperty($listClassId, 'length', JITVariable::TYPE_NATIVE_LONG);
        }
        if (!$objectType->hasProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER)) {
            $objectType->defineProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER, JITVariable::TYPE_VALUE);
        }
        foreach (['__phpcItem0', '__phpcItem1'] as $prop) {
            if (!$objectType->hasProperty($listClassId, $prop)) {
                $objectType->defineProperty($listClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
    }

    private static function loadChildNodesListObject(Context $context, Value $owner): Value
    {
        return self::loadLink($context, $owner, 'DOMElement', VmDom::PROP_CHILD_NODES, 'dom_rm_cn');
    }

    private static function writeChildNodesList(
        Context $context,
        Value $owner,
        int $length,
        ?Value $item0,
        ?Value $item1
    ): void {
        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        $list = $objectType->allocate($listClassId);
        $objectType->markObjectConstructed($list);
        $lengthJit = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt($length, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', 'length'),
            $lengthJit,
            JITVariable::TYPE_NATIVE_LONG
        );
        $ownerJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $owner);
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $ownerJit,
            JITVariable::TYPE_VALUE
        );
        if (null !== $item0) {
            $i0 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item0);
            $objectType->propertyStore(
                $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem0'),
                $i0,
                JITVariable::TYPE_VALUE
            );
        }
        if (null !== $item1) {
            $i1 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item1);
            $objectType->propertyStore(
                $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem1'),
                $i1,
                JITVariable::TYPE_VALUE
            );
        }
        $listJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $list);
        $objectType->propertyStore(
            $objectType->propertySlotFor($owner, 'DOMElement', VmDom::PROP_CHILD_NODES),
            $listJit,
            JITVariable::TYPE_VALUE
        );
    }

    private static function storeChildEdge(
        Context $context,
        Value $parent,
        string $prop,
        JITVariable $value
    ): void {
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($parent, 'DOMElement', $prop),
            $value,
            JITVariable::TYPE_VALUE
        );
    }

    private static function loadChildEdge(
        Context $context,
        Value $obj,
        string $prop,
        string $label
    ): Value {
        return self::loadLink($context, $obj, 'DOMElement', $prop, $label);
    }

    private static function storeSibling(
        Context $context,
        Value $obj,
        string $prop,
        JITVariable $value
    ): void {
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, 'DOMElement', $prop),
            $value,
            JITVariable::TYPE_VALUE
        );
    }

    private static function loadSibling(
        Context $context,
        Value $obj,
        string $prop,
        string $label
    ): Value {
        return self::loadLink($context, $obj, 'DOMElement', $prop, $label);
    }

    private static function loadLink(
        Context $context,
        Value $obj,
        string $class,
        string $prop,
        string $label
    ): Value {
        $objectType = $context->type->object;
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $slot = $objectType->propertySlotFor($obj, $class, $prop);
        $ptr = $context->builder->load($slot);
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $ptr, $voidPtr->constNull());
        $bbNull = BasicBlockHelper::append($context, $label.'_null');
        $bbRead = BasicBlockHelper::append($context, $label.'_read');
        $bbMerge = BasicBlockHelper::append($context, $label.'_merge');
        $context->builder->branchIf($slotNull, $bbNull, $bbRead);

        $context->builder->positionAtEnd($bbNull);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbRead);
        $loaded = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($ptr, $context->getTypeFromString('__value__*'))
        );
        $readPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbMerge);
        $phi = $context->builder->phi($objPtrTy);
        $phi->addIncoming($objPtrTy->constNull(), $bbNull);
        $phi->addIncoming($loaded, $readPred);

        return $phi;
    }

    private static function nullValueVar(Context $context): JITVariable
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $ptr)
        );
    }

    private static function objectOrNullVar(Context $context, Value $obj): JITVariable
    {
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $obj, $objPtrTy->constNull());
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $bbNull = BasicBlockHelper::append($context, 'dom_rm_box_null');
        $bbObj = BasicBlockHelper::append($context, 'dom_rm_box_obj');
        $bbMerge = BasicBlockHelper::append($context, 'dom_rm_box_merge');
        $context->builder->branchIf($isNull, $bbNull, $bbObj);

        $context->builder->positionAtEnd($bbNull);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbObj);
        $context->builder->call($context->lookupFunction('__value__writeObject'), $ptr, $obj);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbMerge);

        return new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $ptr)
        );
    }
}
