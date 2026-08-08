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
 * Thin-AOT LLVM slot sync for Element DOMNode::appendChild() (#27476).
 *
 * Peer {@see JitDomInsertBefore}: skip NestedJIT for createElement nodes.
 * Parent firstChild/lastChild use DOMNode (matches syncChildLinkSlots +
 * JitDomNodeChildProperty). Sibling/parentNode on children use DOMElement
 * (createElement layout; DOMNode sibling aliases parentNode).
 * Move detection uses first/last identity — parentNode slots are unreliable after
 * lastChild stores on thin AOT (#27476).
 *
 * Reference: php-src ext/dom/node.c dom_node_append_child.
 * Peer: {@see JitDomInsertBefore}.
 */
final class JitDomAppendChildLiveSlots
{
    public static function sync(Context $context, Value $parent, Value $child): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ac_live_slots');
        self::ensureLayout($context);

        $objectType = $context->type->object;
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');

        $childJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $child);
        $nullBox = self::nullValueVar($context);

        // Detect same-parent move without parentNode: thin-AOT lastChild stores can
        // clear sibling parentNode slots (#27476). If child is already first or last,
        // this is a reparent-within-parent (php-src dom_node_append_child move path).
        $curFirst0 = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_acls_chk_first');
        $curLast0 = self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, 'dom_acls_chk_last');
        $isFirstChild = $context->builder->icmp(Builder::INT_EQ, $curFirst0, $child);
        $isLastChild = $context->builder->icmp(Builder::INT_EQ, $curLast0, $child);
        $isMove = $context->builder->or($isFirstChild, $isLastChild);

        $bbMove = BasicBlockHelper::append($context, 'dom_acls_move');
        $bbFresh = BasicBlockHelper::append($context, 'dom_acls_fresh');
        $bbDone = BasicBlockHelper::append($context, 'dom_acls_done');
        $context->builder->branchIf($isMove, $bbMove, $bbFresh);

        // ---- Same-parent move ----
        $context->builder->positionAtEnd($bbMove);
        $prev = self::loadSibling($context, $child, VmDom::PROP_PREVIOUS_SIBLING, 'dom_acls_prev');
        $next = self::loadSibling($context, $child, VmDom::PROP_NEXT_SIBLING, 'dom_acls_next');
        $first = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_acls_mfirst');
        $last = self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, 'dom_acls_mlast');

        $bbPrevLink = BasicBlockHelper::append($context, 'dom_acls_prev_link');
        $bbAfterPrev = BasicBlockHelper::append($context, 'dom_acls_after_prev');
        $prevNull = $context->builder->icmp(Builder::INT_EQ, $prev, $objPtrTy->constNull());
        $context->builder->branchIf($prevNull, $bbAfterPrev, $bbPrevLink);
        $context->builder->positionAtEnd($bbPrevLink);
        self::storeSibling($context, $prev, VmDom::PROP_NEXT_SIBLING, self::objectOrNullVar($context, $next));
        $context->builder->branch($bbAfterPrev);

        $context->builder->positionAtEnd($bbAfterPrev);
        $bbNextLink = BasicBlockHelper::append($context, 'dom_acls_next_link');
        $bbAfterNext = BasicBlockHelper::append($context, 'dom_acls_after_next');
        $nextNull = $context->builder->icmp(Builder::INT_EQ, $next, $objPtrTy->constNull());
        $context->builder->branchIf($nextNull, $bbAfterNext, $bbNextLink);
        $context->builder->positionAtEnd($bbNextLink);
        self::storeSibling($context, $next, VmDom::PROP_PREVIOUS_SIBLING, self::objectOrNullVar($context, $prev));
        $context->builder->branch($bbAfterNext);

        $context->builder->positionAtEnd($bbAfterNext);
        $firstIsChild = $context->builder->icmp(Builder::INT_EQ, $first, $child);
        $bbSetFirst = BasicBlockHelper::append($context, 'dom_acls_set_first');
        $bbAfterFirst = BasicBlockHelper::append($context, 'dom_acls_after_first');
        $context->builder->branchIf($firstIsChild, $bbSetFirst, $bbAfterFirst);
        $context->builder->positionAtEnd($bbSetFirst);
        self::storeChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, self::objectOrNullVar($context, $next));
        $context->builder->branch($bbAfterFirst);

        $context->builder->positionAtEnd($bbAfterFirst);
        $lastIsChild = $context->builder->icmp(Builder::INT_EQ, $last, $child);
        $bbSetLast = BasicBlockHelper::append($context, 'dom_acls_set_last');
        $bbAfterLast = BasicBlockHelper::append($context, 'dom_acls_after_last');
        $context->builder->branchIf($lastIsChild, $bbSetLast, $bbAfterLast);
        $context->builder->positionAtEnd($bbSetLast);
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, self::objectOrNullVar($context, $prev));
        $context->builder->branch($bbAfterLast);

        $context->builder->positionAtEnd($bbAfterLast);
        self::storeSibling($context, $child, VmDom::PROP_PREVIOUS_SIBLING, $nullBox);
        self::storeSibling($context, $child, VmDom::PROP_NEXT_SIBLING, $nullBox);

        $tail = self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, 'dom_acls_tail');
        $tailNull = $context->builder->icmp(Builder::INT_EQ, $tail, $objPtrTy->constNull());
        $bbEmptyAfter = BasicBlockHelper::append($context, 'dom_acls_empty_after');
        $bbLinkTail = BasicBlockHelper::append($context, 'dom_acls_link_tail');
        $context->builder->branchIf($tailNull, $bbEmptyAfter, $bbLinkTail);

        $context->builder->positionAtEnd($bbEmptyAfter);
        self::storeChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, $childJit);
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, $childJit);
        self::writeChildNodesList($context, $parent, 1, $child, null);
        self::storeParentNode($context, $child, $parent);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbLinkTail);
        self::storeSibling($context, $tail, VmDom::PROP_NEXT_SIBLING, $childJit);
        self::storeSibling(
            $context,
            $child,
            VmDom::PROP_PREVIOUS_SIBLING,
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $tail)
        );
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, $childJit);
        $newFirst = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_acls_nfirst');
        self::writeChildNodesList($context, $parent, 2, $newFirst, $child);
        self::storeParentNode($context, $child, $parent);
        $context->builder->branch($bbDone);

        // ---- Fresh append ----
        $context->builder->positionAtEnd($bbFresh);
        $curFirst = self::loadChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, 'dom_acls_ffirst');
        $curLast = self::loadChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, 'dom_acls_flast');
        $empty = $context->builder->icmp(Builder::INT_EQ, $curFirst, $objPtrTy->constNull());
        $bbFirstChild = BasicBlockHelper::append($context, 'dom_acls_first_child');
        $bbAppendTail = BasicBlockHelper::append($context, 'dom_acls_append_tail');
        $context->builder->branchIf($empty, $bbFirstChild, $bbAppendTail);

        $context->builder->positionAtEnd($bbFirstChild);
        self::storeChildEdge($context, $parent, VmDom::PROP_FIRST_CHILD, $childJit);
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, $childJit);
        self::storeSibling($context, $child, VmDom::PROP_PREVIOUS_SIBLING, $nullBox);
        self::storeSibling($context, $child, VmDom::PROP_NEXT_SIBLING, $nullBox);
        self::writeChildNodesList($context, $parent, 1, $child, null);
        self::storeParentNode($context, $child, $parent);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbAppendTail);
        $tailNull2 = $context->builder->icmp(Builder::INT_EQ, $curLast, $objPtrTy->constNull());
        $useFirstAsTail = BasicBlockHelper::append($context, 'dom_acls_use_first_tail');
        $haveTail = BasicBlockHelper::append($context, 'dom_acls_have_tail');
        $context->builder->branchIf($tailNull2, $useFirstAsTail, $haveTail);
        $context->builder->positionAtEnd($useFirstAsTail);
        $context->builder->branch($haveTail);
        $context->builder->positionAtEnd($haveTail);
        $tailPhi = $context->builder->phi($objPtrTy);
        $tailPhi->addIncoming($curFirst, $useFirstAsTail);
        $tailPhi->addIncoming($curLast, $bbAppendTail);

        self::storeSibling($context, $tailPhi, VmDom::PROP_NEXT_SIBLING, $childJit);
        self::storeSibling(
            $context,
            $child,
            VmDom::PROP_PREVIOUS_SIBLING,
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $tailPhi)
        );
        self::storeSibling($context, $child, VmDom::PROP_NEXT_SIBLING, $nullBox);
        self::storeChildEdge($context, $parent, VmDom::PROP_LAST_CHILD, $childJit);
        // +1 in place — absolute writeChildNodesList(..., 2) left loadXML-seeded
        // held `$list = $parent->childNodes` stale at length 2 (#29048 / re-#28509).
        self::incrementChildNodesLengthInPlace($context, $parent, $curFirst, $child);
        self::storeParentNode($context, $child, $parent);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    private static function ensureLayout(Context $context): void
    {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $elementClassId = $objectType->lookup('DOMElement');
        $listClassId = $objectType->lookup('DOMNodeList');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        foreach ([
            VmDom::PROP_NEXT_SIBLING,
            VmDom::PROP_PREVIOUS_SIBLING,
            VmDom::PROP_PARENT_NODE,
            // Do NOT define childNodes/firstChild/lastChild on DOMElement here —
            // createElement already baked those (sans childNodes); mid-function
            // defineProperty after allocate OOBs Element-typed $el->childNodes (#24973).
            // Stores/fetches for first/last/childNodes use DOMNode (peer insertBefore).
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
        // DOMNode — same as syncChildLinkSlots / JitDomInsertBefore / property fetch.
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($parent, 'DOMNode', $prop),
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
        return self::loadLink($context, $obj, 'DOMNode', $prop, $label);
    }

    private static function storeSibling(
        Context $context,
        Value $obj,
        string $prop,
        JITVariable $value
    ): void {
        // DOMElement only — DOMNode nextSibling aliases parentNode (#27476).
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

    /**
     * Bump an existing childNodes list by 1 (or seed length=2) without replacing
     * the list object — held `$list = $node->childNodes` must observe the update
     * (#29048, php-src nodelist.c live collection).
     */
    private static function incrementChildNodesLengthInPlace(
        Context $context,
        Value $owner,
        Value $item0,
        Value $item1
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_acls_inc_len');
        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        $nodeClassId = $objectType->lookup('DOMNode');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        if (!$objectType->hasProperty($nodeClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($nodeClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
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

        $existing = self::loadChildNodesListObject($context, $owner);
        $missing = $context->builder->icmp(Builder::INT_EQ, $existing, $objPtrTy->constNull());
        $bbSeed = BasicBlockHelper::append($context, 'dom_acls_inc_seed');
        $bbBump = BasicBlockHelper::append($context, 'dom_acls_inc_bump');
        $bbDone = BasicBlockHelper::append($context, 'dom_acls_inc_done');
        $context->builder->branchIf($missing, $bbSeed, $bbBump);

        $context->builder->positionAtEnd($bbSeed);
        // No prior list (should be rare on append-tail) — seed length=2 + pins.
        self::writeChildNodesList($context, $owner, 2, $item0, $item1);
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
        $ownerJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $owner);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $ownerJit,
            JITVariable::TYPE_VALUE
        );
        $i0 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item0);
        $i1 = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $item1);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem0'),
            $i0,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem1'),
            $i1,
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    /** Load parent.childNodes object from a TYPE_VALUE slot (null if unset). */
    private static function loadChildNodesListObject(Context $context, Value $owner): Value
    {
        return self::loadLink($context, $owner, 'DOMNode', VmDom::PROP_CHILD_NODES, 'dom_acls_cn');
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
            $objectType->propertySlotFor($owner, 'DOMNode', VmDom::PROP_CHILD_NODES),
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
        $bbNull = BasicBlockHelper::append($context, 'dom_acls_box_null');
        $bbObj = BasicBlockHelper::append($context, 'dom_acls_box_obj');
        $bbMerge = BasicBlockHelper::append($context, 'dom_acls_box_merge');
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
