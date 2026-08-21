<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT DocumentFragment expand for appendChild (#33312).
 *
 * createDocumentFragment allocates a DOMElement stand-in with nodeName
 * {@code #document-fragment} ({@see JitDomCreateDocumentFragment}). LiveSlots
 * must steal the fragment's firstChild…lastChild chain onto the parent — not
 * treat the stand-in as a normal child (php-src ext/dom/node.c
 * dom_node_append_child fragment path; VM peer
 * {@see VmDom::insertFragmentChildrenBefore}).
 *
 * Detection uses nodeName strcmp — stand-in class_id is DOMElement, not
 * DOMDocumentFragment (peer {@see JitDomSaveXMLUserScript} fragment branch).
 */
final class JitDomDocumentFragmentExpand
{
    /**
     * If $child is a fragment stand-in, expand onto $parent and branch to $bbDone.
     * Otherwise branch to $bbNotFrag (caller continues normal LiveSlots).
     *
     * @param mixed $bbNotFrag
     * @param mixed $bbDone
     */
    public static function branchAppendIfFragment(
        Context $context,
        Value $parent,
        Value $child,
        $bbNotFrag,
        $bbDone
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_frag_expand_chk');
        self::ensureLayout($context);

        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        $nameVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $child,
            'DOMElement',
            'nodeName',
            $elementClassId
        );
        $nameStr = $context->helper->loadValue($nameVar);
        $fragLit = $context->builder->load($context->constantStringFromString('#document-fragment'));
        $i64 = $context->getTypeFromString('int64');
        $isFrag = $context->builder->icmp(
            Builder::INT_EQ,
            JitStringCompare::strcmp($context, $nameStr, $fragLit),
            $i64->constInt(0, false)
        );
        $bbFrag = BasicBlockHelper::append($context, 'dom_frag_expand_yes');
        $context->builder->branchIf($isFrag, $bbFrag, $bbNotFrag);

        $context->builder->positionAtEnd($bbFrag);
        self::expandAppend($context, $parent, $child);
        $context->builder->branch($bbDone);
    }

    /** Steal fragment children onto parent (append). Empty fragment is a no-op. */
    private static function expandAppend(Context $context, Value $parent, Value $fragment): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_frag_expand_append');
        self::ensureLayout($context);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $nullBox = self::nullValueVar($context);
        $parentJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parent);

        $fragFirst = JitDomParentChildLinkLayout::loadFirstChild($context, $fragment, 'dom_frag_ff');
        $fragLast = JitDomParentChildLinkLayout::loadLastChild($context, $fragment, 'dom_frag_fl');
        $empty = $context->builder->icmp(Builder::INT_EQ, $fragFirst, $objPtrTy->constNull());
        $bbEmpty = BasicBlockHelper::append($context, 'dom_frag_empty');
        $bbSteal = BasicBlockHelper::append($context, 'dom_frag_steal');
        $bbAfter = BasicBlockHelper::append($context, 'dom_frag_after');
        $context->builder->branchIf($empty, $bbEmpty, $bbSteal);

        $context->builder->positionAtEnd($bbEmpty);
        self::clearFragmentEdges($context, $fragment, $nullBox);
        self::zeroFragmentChildNodes($context, $fragment);
        JitDomCreateElement::storeUserScriptInnerXml($context, $fragment, '');
        $context->builder->branch($bbAfter);

        $context->builder->positionAtEnd($bbSteal);
        $parentFirst = JitDomParentChildLinkLayout::loadFirstChild($context, $parent, 'dom_frag_pf');
        $parentLast = JitDomParentChildLinkLayout::loadLastChild($context, $parent, 'dom_frag_pl');
        $parentEmpty = $context->builder->icmp(Builder::INT_EQ, $parentFirst, $objPtrTy->constNull());
        $bbParentEmpty = BasicBlockHelper::append($context, 'dom_frag_parent_empty');
        $bbParentLink = BasicBlockHelper::append($context, 'dom_frag_parent_link');
        $bbLinked = BasicBlockHelper::append($context, 'dom_frag_linked');
        $context->builder->branchIf($parentEmpty, $bbParentEmpty, $bbParentLink);

        $context->builder->positionAtEnd($bbParentEmpty);
        $firstJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $fragFirst);
        $lastJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $fragLast);
        JitDomParentChildLinkLayout::storeFirstChild($context, $parent, $firstJit);
        JitDomParentChildLinkLayout::storeLastChild($context, $parent, $lastJit);
        $context->builder->branch($bbLinked);

        $context->builder->positionAtEnd($bbParentLink);
        $fragFirstJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $fragFirst);
        $fragLastJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $fragLast);
        JitDomParentChildLinkLayout::storeSibling(
            $context,
            $parentLast,
            VmDom::PROP_NEXT_SIBLING,
            $fragFirstJit
        );
        JitDomParentChildLinkLayout::storeSibling(
            $context,
            $fragFirst,
            VmDom::PROP_PREVIOUS_SIBLING,
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parentLast)
        );
        JitDomParentChildLinkLayout::storeLastChild($context, $parent, $fragLastJit);
        $context->builder->branch($bbLinked);

        $context->builder->positionAtEnd($bbLinked);
        $i64 = $context->getTypeFromString('int64');
        $loopHdr = BasicBlockHelper::append($context, 'dom_frag_walk_hdr');
        $loopBody = BasicBlockHelper::append($context, 'dom_frag_walk_body');
        $loopDone = BasicBlockHelper::append($context, 'dom_frag_walk_done');
        $enterPred = $context->builder->getInsertBlock();
        $context->builder->branch($loopHdr);

        $context->builder->positionAtEnd($loopHdr);
        $curPhi = $context->builder->phi($objPtrTy);
        $cntPhi = $context->builder->phi($i64);
        $curNull = $context->builder->icmp(Builder::INT_EQ, $curPhi, $objPtrTy->constNull());
        $context->builder->branchIf($curNull, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        JitDomParentChildLinkLayout::storeParentNode($context, $curPhi, $parentJit);
        $next = JitDomParentChildLinkLayout::loadSibling(
            $context,
            $curPhi,
            VmDom::PROP_NEXT_SIBLING,
            'dom_frag_walk_next'
        );
        $nextCnt = $context->builder->add($cntPhi, $i64->constInt(1, false));
        $back = $context->builder->getInsertBlock();
        $context->builder->branch($loopHdr);

        $curPhi->addIncoming($fragFirst, $enterPred);
        $curPhi->addIncoming($next, $back);
        $cntPhi->addIncoming($i64->constInt(0, false), $enterPred);
        $cntPhi->addIncoming($nextCnt, $back);

        $context->builder->positionAtEnd($loopDone);
        self::clearFragmentEdges($context, $fragment, $nullBox);
        self::zeroFragmentChildNodes($context, $fragment);
        self::concatFragmentInnerXmlOntoParent($context, $parent, $fragment);
        JitDomCreateElement::storeUserScriptInnerXml($context, $fragment, '');
        self::addToChildNodesLength($context, $parent, $cntPhi);
        $context->builder->branch($bbAfter);

        $context->builder->positionAtEnd($bbAfter);
    }

    private static function concatFragmentInnerXmlOntoParent(
        Context $context,
        Value $parent,
        Value $fragment
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_frag_inner_xml');
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_USER_SCRIPT_INNER_XML)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_USER_SCRIPT_INNER_XML, JITVariable::TYPE_STRING);
        }
        $parentVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $parent,
            'DOMElement',
            VmDom::PROP_USER_SCRIPT_INNER_XML,
            $elementClassId
        );
        $fragVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $fragment,
            'DOMElement',
            VmDom::PROP_USER_SCRIPT_INNER_XML,
            $elementClassId
        );
        $parentStr = $context->helper->loadValue($parentVar);
        $fragStr = $context->helper->loadValue($fragVar);
        $merged = JitStringConcat::concat($context, $parentStr, $fragStr);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $merged
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, 'DOMElement', VmDom::PROP_USER_SCRIPT_INNER_XML),
            $propVar,
            JITVariable::TYPE_STRING
        );
    }

    private static function addToChildNodesLength(Context $context, Value $owner, Value $delta): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_frag_add_len');
        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        $elementClassId = $objectType->lookup('DOMElement');
        $objPtrTy = $context->getTypeFromString('__object__*');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
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

        $item0 = JitDomParentChildLinkLayout::loadFirstChild($context, $owner, 'dom_frag_pin');
        $bbPin1Null = BasicBlockHelper::append($context, 'dom_frag_pin1_null');
        $bbPin1Read = BasicBlockHelper::append($context, 'dom_frag_pin1_read');
        $bbPin1Merge = BasicBlockHelper::append($context, 'dom_frag_pin1_merge');
        $firstNull = $context->builder->icmp(Builder::INT_EQ, $item0, $objPtrTy->constNull());
        $context->builder->branchIf($firstNull, $bbPin1Null, $bbPin1Read);
        $context->builder->positionAtEnd($bbPin1Null);
        $nullPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbPin1Merge);
        $context->builder->positionAtEnd($bbPin1Read);
        $loadedSecond = JitDomParentChildLinkLayout::loadSibling(
            $context,
            $item0,
            VmDom::PROP_NEXT_SIBLING,
            'dom_frag_pin1'
        );
        $readPred = $context->builder->getInsertBlock();
        $context->builder->branch($bbPin1Merge);
        $context->builder->positionAtEnd($bbPin1Merge);
        $item1 = $context->builder->phi($objPtrTy);
        $item1->addIncoming($objPtrTy->constNull(), $nullPred);
        $item1->addIncoming($loadedSecond, $readPred);

        $existing = self::loadChildNodesList($context, $owner);
        $missing = $context->builder->icmp(Builder::INT_EQ, $existing, $objPtrTy->constNull());
        $bbSeed = BasicBlockHelper::append($context, 'dom_frag_len_seed');
        $bbBump = BasicBlockHelper::append($context, 'dom_frag_len_bump');
        $bbDone = BasicBlockHelper::append($context, 'dom_frag_len_done');
        $context->builder->branchIf($missing, $bbSeed, $bbBump);

        $context->builder->positionAtEnd($bbSeed);
        $list = $objectType->allocate($listClassId);
        $objectType->markObjectConstructed($list);
        $lenJit = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $delta);
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', 'length'),
            $lenJit,
            JITVariable::TYPE_NATIVE_LONG
        );
        $ownerJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $owner);
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $ownerJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem0'),
            self::objectOrNullVar($context, $item0),
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', '__phpcItem1'),
            self::objectOrNullVar($context, $item1),
            JITVariable::TYPE_VALUE
        );
        $listJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $list);
        $objectType->propertyStore(
            $objectType->propertySlotFor($owner, 'DOMElement', VmDom::PROP_CHILD_NODES),
            $listJit,
            JITVariable::TYPE_VALUE
        );
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
        $next = $context->builder->add($current, $delta);
        $nextJit = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $next);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', 'length'),
            $nextJit,
            JITVariable::TYPE_NATIVE_LONG
        );
        $bumpOwnerJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $owner);
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER),
            $bumpOwnerJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem0'),
            self::objectOrNullVar($context, $item0),
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($existing, 'DOMNodeList', '__phpcItem1'),
            self::objectOrNullVar($context, $item1),
            JITVariable::TYPE_VALUE
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    private static function clearFragmentEdges(Context $context, Value $fragment, JITVariable $nullBox): void
    {
        JitDomParentChildLinkLayout::storeFirstChild($context, $fragment, $nullBox);
        JitDomParentChildLinkLayout::storeLastChild($context, $fragment, $nullBox);
    }

    private static function zeroFragmentChildNodes(Context $context, Value $fragment): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_frag_zero_cn');
        $objectType = $context->type->object;
        $listClassId = $objectType->lookup('DOMNodeList');
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_CHILD_NODES)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_CHILD_NODES, JITVariable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($listClassId, 'length')) {
            $objectType->defineProperty($listClassId, 'length', JITVariable::TYPE_NATIVE_LONG);
        }
        $list = $objectType->allocate($listClassId);
        $objectType->markObjectConstructed($list);
        $zero = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($list, 'DOMNodeList', 'length'),
            $zero,
            JITVariable::TYPE_NATIVE_LONG
        );
        $listJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $list);
        $objectType->propertyStore(
            $objectType->propertySlotFor($fragment, 'DOMElement', VmDom::PROP_CHILD_NODES),
            $listJit,
            JITVariable::TYPE_VALUE
        );
    }

    private static function loadChildNodesList(Context $context, Value $owner): Value
    {
        $objectType = $context->type->object;
        $voidPtr = $context->getTypeFromString('void*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $slot = $objectType->propertySlotFor($owner, 'DOMElement', VmDom::PROP_CHILD_NODES);
        $ptr = $context->builder->load($slot);
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $ptr, $voidPtr->constNull());
        $bbNull = BasicBlockHelper::append($context, 'dom_frag_cn_null');
        $bbRead = BasicBlockHelper::append($context, 'dom_frag_cn_read');
        $bbMerge = BasicBlockHelper::append($context, 'dom_frag_cn_merge');
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

    private static function ensureLayout(Context $context): void
    {
        JitDomParentChildLinkLayout::ensureChildEdgeProperties($context);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([
            'nodeName' => JITVariable::TYPE_STRING,
            VmDom::PROP_USER_SCRIPT_INNER_XML => JITVariable::TYPE_STRING,
            VmDom::PROP_NEXT_SIBLING => JITVariable::TYPE_VALUE,
            VmDom::PROP_PREVIOUS_SIBLING => JITVariable::TYPE_VALUE,
            VmDom::PROP_PARENT_NODE => JITVariable::TYPE_VALUE,
            VmDom::PROP_CHILD_NODES => JITVariable::TYPE_VALUE,
        ] as $prop => $type) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, $type);
            }
        }
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
        $bbNull = BasicBlockHelper::append($context, 'dom_frag_box_null');
        $bbObj = BasicBlockHelper::append($context, 'dom_frag_box_obj');
        $bbMerge = BasicBlockHelper::append($context, 'dom_frag_box_merge');
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
