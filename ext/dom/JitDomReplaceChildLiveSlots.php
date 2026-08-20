<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT LLVM slot sync for DOMNode::replaceChild() (#28671, #32784).
 *
 * Peer {@see JitDomAppendChildLiveSlots} / {@see JitDomRemoveChildLiveSlots}:
 * splice newChild into oldChild's sibling chain; update first/last only when
 * old was an edge. Then **refresh length/pins in place** on the existing
 * childNodes list so held `$list = $parent->childNodes` stays live (php-src
 * nodelist.c). Allocating a fresh list left held `__phpcItem*` pins stale —
 * item(1) fell back to lastChild and item(2) aborted.
 *
 * Reference: php-src ext/dom/node.c dom_node_replace_child.
 */
final class JitDomReplaceChildLiveSlots
{
    /**
     * @param int|null $childCount Known post-replace childNodes length (replace keeps
     *                             count for non-fragment); null → derive from first==last.
     */
    public static function sync(
        Context $context,
        Value $parent,
        Value $newChild,
        Value $oldChild,
        ?int $childCount = null
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rc_live_slots');
        self::ensureLayout($context);

        $objPtrTy = $context->getTypeFromString('__object__*');
        $newJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newChild);
        $nullBox = self::nullValueVar($context);

        $prev = self::loadSibling($context, $oldChild, VmDom::PROP_PREVIOUS_SIBLING, 'dom_rc_prev');
        $next = self::loadSibling($context, $oldChild, VmDom::PROP_NEXT_SIBLING, 'dom_rc_next');
        $first = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_rc_first');
        $last = self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, 'dom_rc_last');

        // prev.next = newChild (when prev non-null)
        $bbPrevLink = BasicBlockHelper::append($context, 'dom_rc_prev_link');
        $bbAfterPrev = BasicBlockHelper::append($context, 'dom_rc_after_prev');
        $prevNull = $context->builder->icmp(Builder::INT_EQ, $prev, $objPtrTy->constNull());
        $context->builder->branchIf($prevNull, $bbAfterPrev, $bbPrevLink);
        $context->builder->positionAtEnd($bbPrevLink);
        self::storeSibling($context, $prev, VmDom::PROP_NEXT_SIBLING, $newJit);
        $context->builder->branch($bbAfterPrev);

        // next.prev = newChild (when next non-null)
        $context->builder->positionAtEnd($bbAfterPrev);
        $bbNextLink = BasicBlockHelper::append($context, 'dom_rc_next_link');
        $bbAfterNext = BasicBlockHelper::append($context, 'dom_rc_after_next');
        $nextNull = $context->builder->icmp(Builder::INT_EQ, $next, $objPtrTy->constNull());
        $context->builder->branchIf($nextNull, $bbAfterNext, $bbNextLink);
        $context->builder->positionAtEnd($bbNextLink);
        self::storeSibling($context, $next, VmDom::PROP_PREVIOUS_SIBLING, $newJit);
        $context->builder->branch($bbAfterNext);

        // firstChild ← newChild when replacing the first
        $context->builder->positionAtEnd($bbAfterNext);
        $bbSetFirst = BasicBlockHelper::append($context, 'dom_rc_set_first');
        $bbAfterFirst = BasicBlockHelper::append($context, 'dom_rc_after_first');
        $firstIsOld = $context->builder->icmp(Builder::INT_EQ, $first, $oldChild);
        $context->builder->branchIf($firstIsOld, $bbSetFirst, $bbAfterFirst);
        $context->builder->positionAtEnd($bbSetFirst);
        self::storeChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, $newJit);
        $context->builder->branch($bbAfterFirst);

        // lastChild ← newChild when replacing the last
        $context->builder->positionAtEnd($bbAfterFirst);
        $bbSetLast = BasicBlockHelper::append($context, 'dom_rc_set_last');
        $bbAfterLast = BasicBlockHelper::append($context, 'dom_rc_after_last');
        $lastIsOld = $context->builder->icmp(Builder::INT_EQ, $last, $oldChild);
        $context->builder->branchIf($lastIsOld, $bbSetLast, $bbAfterLast);
        $context->builder->positionAtEnd($bbSetLast);
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, $newJit);
        $context->builder->branch($bbAfterLast);

        $context->builder->positionAtEnd($bbAfterLast);
        self::storeSibling($context, $newChild, VmDom::PROP_PREVIOUS_SIBLING, self::objectOrNullVar($context, $prev));
        self::storeSibling($context, $newChild, VmDom::PROP_NEXT_SIBLING, self::objectOrNullVar($context, $next));
        self::storeParentNode($context, $newChild, $parent);

        // Detach old — null parent/sibling on DOMElement layout (#28672 / #27411).
        self::storeSibling($context, $oldChild, VmDom::PROP_PREVIOUS_SIBLING, $nullBox);
        self::storeSibling($context, $oldChild, VmDom::PROP_NEXT_SIBLING, $nullBox);
        self::storeParentNodeNull($context, $oldChild);

        $newFirst = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_rc_nfirst');
        $item1 = self::loadSibling($context, $newFirst, VmDom::PROP_NEXT_SIBLING, 'dom_rc_item1');
        self::refreshChildNodesListInPlace($context, $parent, $newFirst, $item1, $childCount);
    }

    /**
     * Keep the existing childNodes object (held `$list`) and refresh length +
     * `__phpcItem0`/`__phpcItem1` from the post-replace sibling chain (#32784).
     *
     * @param int|null $childCount Known post-replace length; null → keep existing
     *                             length when a list is already attached.
     */
    private static function refreshChildNodesListInPlace(
        Context $context,
        Value $owner,
        Value $newFirst,
        Value $item1,
        ?int $childCount
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rc_refresh_cn');
        $objectType = $context->type->object;
        $objPtrTy = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $nullBox = self::nullValueVar($context);

        $existing = self::loadChildNodesListObject($context, $owner);
        $missing = $context->builder->icmp(Builder::INT_EQ, $existing, $objPtrTy->constNull());
        $bbSeed = BasicBlockHelper::append($context, 'dom_rc_cn_seed');
        $bbRefresh = BasicBlockHelper::append($context, 'dom_rc_cn_refresh');
        $bbDone = BasicBlockHelper::append($context, 'dom_rc_cn_done');
        $context->builder->branchIf($missing, $bbSeed, $bbRefresh);

        // No prior list — allocate (same 1/2 heuristic when count unknown).
        $context->builder->positionAtEnd($bbSeed);
        if (null !== $childCount && $childCount >= 1) {
            self::writeChildNodesList(
                $context,
                $owner,
                $childCount,
                $newFirst,
                $childCount >= 2 ? $item1 : null
            );
            $context->builder->branch($bbDone);
        } else {
            $newLast = self::loadChildEdge($context, $owner, VmDom::PROP_LAST_CHILD, 'dom_rc_seed_last');
            $same = $context->builder->icmp(Builder::INT_EQ, $newFirst, $newLast);
            $bbOne = BasicBlockHelper::append($context, 'dom_rc_seed_one');
            $bbTwo = BasicBlockHelper::append($context, 'dom_rc_seed_two');
            $context->builder->branchIf($same, $bbOne, $bbTwo);
            $context->builder->positionAtEnd($bbOne);
            self::writeChildNodesList($context, $owner, 1, $newFirst, null);
            $context->builder->branch($bbDone);
            $context->builder->positionAtEnd($bbTwo);
            self::writeChildNodesList($context, $owner, 2, $newFirst, $item1);
            $context->builder->branch($bbDone);
        }

        $context->builder->positionAtEnd($bbRefresh);
        if (null !== $childCount && $childCount >= 0) {
            $lengthJit = new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($childCount, false)
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($existing, 'DOMNodeList', 'length'),
                $lengthJit,
                JITVariable::TYPE_NATIVE_LONG
            );
        }
        // else: replace keeps child count — leave held length untouched.
        $ownerJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $owner);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $ownerJit,
            JITVariable::TYPE_VALUE
        );

        // Refresh pins — stale __phpcItem1 made item(1) fall back to lastChild (#32784).
        $firstNull = $context->builder->icmp(Builder::INT_EQ, $newFirst, $objPtrTy->constNull());
        $bbClearPins = BasicBlockHelper::append($context, 'dom_rc_cn_clear_pins');
        $bbSetPins = BasicBlockHelper::append($context, 'dom_rc_cn_set_pins');
        $bbPinsDone = BasicBlockHelper::append($context, 'dom_rc_cn_pins_done');
        $context->builder->branchIf($firstNull, $bbClearPins, $bbSetPins);

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
        $secondNull = $context->builder->icmp(Builder::INT_EQ, $item1, $objPtrTy->constNull());
        $bbPin1Null = BasicBlockHelper::append($context, 'dom_rc_cn_pin1_null');
        $bbPin1Set = BasicBlockHelper::append($context, 'dom_rc_cn_pin1_set');
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
        // Keep owner.childNodes pointing at the same list object (do not reallocate).
        $listJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $existing);
        $objectType->propertyStore(
            $objectType->propertySlotFor($owner, 'DOMElement', VmDom::PROP_CHILD_NODES),
            $listJit,
            JITVariable::TYPE_VALUE
        );
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

    private static function storeParentNode(Context $context, Value $child, Value $parent): void
    {
        $parentJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parent);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($child, 'DOMElement', VmDom::PROP_PARENT_NODE),
            $parentJit,
            JITVariable::TYPE_VALUE
        );
    }

    private static function storeParentNodeNull(Context $context, Value $child): void
    {
        self::storeSibling($context, $child, VmDom::PROP_PARENT_NODE, self::nullValueVar($context));
    }

    private static function loadChildNodesListObject(Context $context, Value $owner): Value
    {
        return self::loadLink($context, $owner, 'DOMElement', VmDom::PROP_CHILD_NODES, 'dom_rc_cn');
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
        $bbNull = BasicBlockHelper::append($context, 'dom_rc_box_null');
        $bbObj = BasicBlockHelper::append($context, 'dom_rc_box_obj');
        $bbMerge = BasicBlockHelper::append($context, 'dom_rc_box_merge');
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
