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
 * Thin-AOT LLVM slot sync for DOMNode::insertBefore() (#32801, #33312).
 *
 * Peer {@see JitDomAppendChildLiveSlots} / {@see JitDomReplaceChildLiveSlots} /
 * {@see JitDomRemoveChildLiveSlots}: splice newChild before refChild; refresh the
 * existing childNodes list **in place** so held `$list = $parent->childNodes`
 * observes +1 length and fresh `__phpcItem*` pins (php-src nodelist.c).
 *
 * Prior path always allocated a fresh length=2 list (item0=new, item1=ref),
 * leaving held lists stale and refetch collapsed to 2 (#32801 / re-#32784).
 * DocumentFragment stand-ins expand children before $refChild (#33312).
 * Cross-parent reparent must unlink the old parent first (php-src
 * dom_node_insert_before) — peer appendChild #33404 / #33450.
 *
 * Reference: php-src ext/dom/node.c dom_node_insert_before.
 */
final class JitDomInsertBeforeLiveSlots
{
    public static function sync(
        Context $context,
        Value $parent,
        Value $newChild,
        Value $refChild
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ib_live_slots');
        self::ensureLayout($context);
        JitDomParentChildLinkLayout::ensureChildEdgeProperties($context);

        $bbFrag = BasicBlockHelper::append($context, 'dom_ib_frag');
        $bbNormal = BasicBlockHelper::append($context, 'dom_ib_normal');
        $bbSyncEnd = BasicBlockHelper::append($context, 'dom_ib_sync_end');
        $isFrag = JitDomAppendChildLiveSlots::isDocumentFragmentNode($context, $newChild);
        $context->builder->branchIf($isFrag, $bbFrag, $bbNormal);

        $context->builder->positionAtEnd($bbFrag);
        JitDomAppendChildLiveSlots::expandFragmentChildrenInsertBefore(
            $context,
            $parent,
            $newChild,
            $refChild
        );
        $context->builder->branch($bbSyncEnd);

        $context->builder->positionAtEnd($bbNormal);
        self::syncNonFragment($context, $parent, $newChild, $refChild);
        $context->builder->branch($bbSyncEnd);

        $context->builder->positionAtEnd($bbSyncEnd);
    }

    /**
     * insertBefore one non-fragment child (#32801 / #33312).
     *
     * Fragment expand must call this — not {@see sync} — so codegen does not
     * re-enter expand via the dead fragment IR arm.
     */
    public static function syncNonFragment(
        Context $context,
        Value $parent,
        Value $newChild,
        Value $refChild
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ib_nonfrag');
        self::ensureLayout($context);
        JitDomParentChildLinkLayout::ensureChildEdgeProperties($context);

        $objPtrTy = $context->getTypeFromString('__object__*');
        $newJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newChild);
        $refJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $refChild);
        $parentJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parent);
        $nullBox = self::nullValueVar($context);

        // php-src: if newChild already has a different parent, remove it first (#33450).
        JitDomAppendChildLiveSlots::detachFromForeignParentIfNeeded($context, $parent, $newChild);

        $prev = JitDomParentChildLinkLayout::loadSibling(
            $context,
            $refChild,
            VmDom::PROP_PREVIOUS_SIBLING,
            'dom_ib_prev'
        );

        // prev.next = newChild (when prev non-null)
        $bbPrevLink = BasicBlockHelper::append($context, 'dom_ib_prev_link');
        $bbAfterPrev = BasicBlockHelper::append($context, 'dom_ib_after_prev');
        $prevNull = $context->builder->icmp(Builder::INT_EQ, $prev, $objPtrTy->constNull());
        $context->builder->branchIf($prevNull, $bbAfterPrev, $bbPrevLink);
        $context->builder->positionAtEnd($bbPrevLink);
        JitDomParentChildLinkLayout::storeSibling($context, $prev, VmDom::PROP_NEXT_SIBLING, $newJit);
        $context->builder->branch($bbAfterPrev);

        // firstChild ← newChild when inserting before the current first (prev null)
        $context->builder->positionAtEnd($bbAfterPrev);
        $bbSetFirst = BasicBlockHelper::append($context, 'dom_ib_set_first');
        $bbAfterFirst = BasicBlockHelper::append($context, 'dom_ib_after_first');
        $context->builder->branchIf($prevNull, $bbSetFirst, $bbAfterFirst);
        $context->builder->positionAtEnd($bbSetFirst);
        JitDomParentChildLinkLayout::storeFirstChild($context, $parent, $newJit);
        $context->builder->branch($bbAfterFirst);

        $context->builder->positionAtEnd($bbAfterFirst);
        JitDomParentChildLinkLayout::storeSibling(
            $context,
            $newChild,
            VmDom::PROP_PREVIOUS_SIBLING,
            self::objectOrNullVar($context, $prev)
        );
        JitDomParentChildLinkLayout::storeSibling($context, $newChild, VmDom::PROP_NEXT_SIBLING, $refJit);
        JitDomParentChildLinkLayout::storeSibling($context, $refChild, VmDom::PROP_PREVIOUS_SIBLING, $newJit);
        JitDomParentChildLinkLayout::storeParentNode($context, $newChild, $parentJit);

        // firstElementChild when inserting before current first element (#27449).
        $fecObj = JitDomParentChildLinkLayout::loadFirstElementChild($context, $parent, 'dom_ib');
        $fecIsRef = $context->builder->icmp(Builder::INT_EQ, $fecObj, $refChild);
        $fecIsNull = $context->builder->icmp(Builder::INT_EQ, $fecObj, $objPtrTy->constNull());
        $shouldSetFec = $context->builder->or($fecIsRef, $fecIsNull);
        $setFec = BasicBlockHelper::append($context, 'dom_ib_set_fec');
        $afterFec = BasicBlockHelper::append($context, 'dom_ib_after_fec');
        $context->builder->branchIf($shouldSetFec, $setFec, $afterFec);
        $context->builder->positionAtEnd($setFec);
        JitDomParentChildLinkLayout::storeFirstElementChild($context, $parent, $newJit);
        $context->builder->branch($afterFec);

        $context->builder->positionAtEnd($afterFec);
        self::incrementChildNodesLengthInPlace($context, $parent);
        // saveXML reads PROP_USER_SCRIPT_INNER_XML — rebuild destination + ancestors
        // so createElement trees match Zend after cross-parent insert (#33450).
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlFromElementChildren($context, $parent);
        JitDomAppendChildLiveSlots::rebuildUserScriptInnerXmlUpward($context, $parent);
    }

    /**
     * Bump held childNodes +1 and refresh pins from parent.first / first→next.
     * Used by insertBefore and ChildNode::after append-tail (#32801).
     */
    public static function incrementChildNodesLengthInPlace(Context $context, Value $parent): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ib_inc_len');
        self::ensureLayout($context);
        JitDomParentChildLinkLayout::ensureChildEdgeProperties($context);

        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $nullBox = self::nullValueVar($context);

        $item0 = JitDomParentChildLinkLayout::loadFirstChild($context, $parent, 'dom_ib_pin');
        $bbSecondNull = BasicBlockHelper::append($context, 'dom_ib_pin1_null');
        $bbSecondRead = BasicBlockHelper::append($context, 'dom_ib_pin1_read');
        $bbSecondMerge = BasicBlockHelper::append($context, 'dom_ib_pin1_merge');
        $firstNull = $context->builder->icmp(Builder::INT_EQ, $item0, $objPtrTy->constNull());
        $context->builder->branchIf($firstNull, $bbSecondNull, $bbSecondRead);
        $context->builder->positionAtEnd($bbSecondNull);
        $nullPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbSecondMerge);
        $context->builder->positionAtEnd($bbSecondRead);
        $loadedSecond = JitDomParentChildLinkLayout::loadSibling(
            $context,
            $item0,
            VmDom::PROP_NEXT_SIBLING,
            'dom_ib_pin1'
        );
        $readPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbSecondMerge);
        $context->builder->positionAtEnd($bbSecondMerge);
        $item1 = $context->builder->phi($objPtrTy);
        $item1->addIncoming($objPtrTy->constNull(), $nullPred);
        $item1->addIncoming($loadedSecond, $readPred);

        $existing = self::loadChildNodesListObject($context, $parent);
        $missing = $context->builder->icmp(Builder::INT_EQ, $existing, $objPtrTy->constNull());
        $bbSeed = BasicBlockHelper::append($context, 'dom_ib_inc_seed');
        $bbBump = BasicBlockHelper::append($context, 'dom_ib_inc_bump');
        $bbDone = BasicBlockHelper::append($context, 'dom_ib_inc_done');
        $context->builder->branchIf($missing, $bbSeed, $bbBump);

        $context->builder->positionAtEnd($bbSeed);
        // No prior list — seed length=2 + pins (insert always has a ref sibling).
        self::writeChildNodesList($context, $parent, 2, $item0, $item1);
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
        $next = $context->builder->add($current, $i64->constInt(1, false));
        $nextJit = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $next);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', 'length'),
            $nextJit,
            JITVariable::TYPE_NATIVE_LONG
        );
        $ownerJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parent);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $ownerJit,
            JITVariable::TYPE_VALUE
        );

        $firstNull2 = $context->builder->icmp(Builder::INT_EQ, $item0, $objPtrTy->constNull());
        $bbClearPins = BasicBlockHelper::append($context, 'dom_ib_inc_clear_pins');
        $bbSetPins = BasicBlockHelper::append($context, 'dom_ib_inc_set_pins');
        $bbPinsDone = BasicBlockHelper::append($context, 'dom_ib_inc_pins_done');
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
        $i0 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item0);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem0'),
            $i0,
            JITVariable::TYPE_VALUE
        );
        $secondNull = $context->builder->icmp(Builder::INT_EQ, $item1, $objPtrTy->constNull());
        $bbPin1Null = BasicBlockHelper::append($context, 'dom_ib_inc_pin1_null');
        $bbPin1Set = BasicBlockHelper::append($context, 'dom_ib_inc_pin1_set');
        $context->builder->branchIf($secondNull, $bbPin1Null, $bbPin1Set);
        $context->builder->positionAtEnd($bbPin1Null);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem1'),
            $nullBox,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbPinsDone);
        $context->builder->positionAtEnd($bbPin1Set);
        $i1 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item1);
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
        $nodeClassId = $objectType->lookup('DOMNode');
        $listClassId = $objectType->lookup('DOMNodeList');
        foreach ([VmDom::PROP_CHILD_NODES] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
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
        return self::loadLink($context, $owner, 'DOMElement', VmDom::PROP_CHILD_NODES, 'dom_ib_cn');
    }

    private static function writeChildNodesList(
        Context $context,
        Value $owner,
        int $length,
        Value $item0,
        Value $item1
    ): void {
        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        $objPtrTy = $context->getTypeFromString('__object__*');
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
        $i0Null = $context->builder->icmp(Builder::INT_EQ, $item0, $objPtrTy->constNull());
        $bbI0Null = BasicBlockHelper::append($context, 'dom_ib_seed_i0_null');
        $bbI0Set = BasicBlockHelper::append($context, 'dom_ib_seed_i0_set');
        $bbI0Done = BasicBlockHelper::append($context, 'dom_ib_seed_i0_done');
        $context->builder->branchIf($i0Null, $bbI0Null, $bbI0Set);
        $context->builder->positionAtEnd($bbI0Null);
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem0'),
            self::nullValueVar($context),
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbI0Done);
        $context->builder->positionAtEnd($bbI0Set);
        $i0 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item0);
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem0'),
            $i0,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbI0Done);
        $context->builder->positionAtEnd($bbI0Done);

        $i1Null = $context->builder->icmp(Builder::INT_EQ, $item1, $objPtrTy->constNull());
        $bbI1Null = BasicBlockHelper::append($context, 'dom_ib_seed_i1_null');
        $bbI1Set = BasicBlockHelper::append($context, 'dom_ib_seed_i1_set');
        $bbI1Done = BasicBlockHelper::append($context, 'dom_ib_seed_i1_done');
        $context->builder->branchIf($i1Null, $bbI1Null, $bbI1Set);
        $context->builder->positionAtEnd($bbI1Null);
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem1'),
            self::nullValueVar($context),
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbI1Done);
        $context->builder->positionAtEnd($bbI1Set);
        $i1 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item1);
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem1'),
            $i1,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbI1Done);
        $context->builder->positionAtEnd($bbI1Done);

        $listJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $list);
        $objectType->propertyStore(
            $objectType->propertySlotFor($owner, 'DOMElement', VmDom::PROP_CHILD_NODES),
            $listJit,
            JITVariable::TYPE_VALUE
        );
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
        $bbNull = BasicBlockHelper::append($context, 'dom_ib_box_null');
        $bbObj = BasicBlockHelper::append($context, 'dom_ib_box_obj');
        $bbMerge = BasicBlockHelper::append($context, 'dom_ib_box_merge');
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
