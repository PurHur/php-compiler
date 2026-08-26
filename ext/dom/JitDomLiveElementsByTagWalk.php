<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT live document-order walk for getElementsByTagName NodeLists (#33659).
 *
 * Compile-time loadXML rematerialization misses createElement nodes linked via
 * LiveSlots. Walk pinned documentElement with firstChild / nextSibling /
 * parentNode (php-src nodelist.c document order) and emit matching elements.
 *
 * Peer: {@see JitDomNodeListForeachSnapshot::emitLiveChildNodesSnapshot} (#33645).
 */
final class JitDomLiveElementsByTagWalk
{
    /**
     * Snapshot matching elements under {@code $root} (inclusive) into a hashtable.
     *
     * @return array{0: Value, 1: Value} __hashtable__* and size_t count
     */
    public static function snapshotToHashtable(Context $context, Value $root, string $tag): array
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_live_tag_snap');
        self::ensureLayout($context);
        $sizeT = $context->getTypeFromString('size_t');
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $objPtrTy = $context->getTypeFromString('__object__*');

        $htSlot = BasicBlockHelper::entryAlloca($context, $htPtrTy);
        $countSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($htPtrTy->constNull(), $htSlot);
        $context->builder->store($sizeT->constInt(0, false), $countSlot);

        $rootNull = $context->builder->icmp(Builder::INT_EQ, $root, $objPtrTy->constNull());
        $bbEmpty = BasicBlockHelper::append($context, 'dom_live_tag_snap_empty');
        $bbWalk = BasicBlockHelper::append($context, 'dom_live_tag_snap_walk');
        $bbDone = BasicBlockHelper::append($context, 'dom_live_tag_snap_done');
        $context->builder->branchIf($rootNull, $bbEmpty, $bbWalk);

        $context->builder->positionAtEnd($bbEmpty);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__hashtable__grow'),
            $emptyHt,
            $sizeT->constInt(1, false)
        );
        $context->builder->store($emptyHt, $htSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbWalk);
        // Grow from live GLOBAL_COUNT when available, else a small default.
        $growN = $sizeT->constInt(16, false);
        if (null !== $context->module->getNamedGlobal(DomUserScriptLiveTagListLlvm::GLOBAL_COUNT)) {
            $live = DomUserScriptLiveTagListLlvm::readStoredCount($context);
            $liveSz = $context->builder->intCast($live, $sizeT);
            $need = $context->builder->icmp(Builder::INT_UGT, $liveSz, $growN);
            $growN = $context->builder->select($need, $liveSz, $growN);
        }
        $ht = HashTableHelper::alloc($context);
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $ht, $growN);
        $context->builder->store($ht, $htSlot);

        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);
        self::emitPreorderCollect($context, $root, $tag, $ht, $idxSlot);
        $finalIdx = $context->builder->load($idxSlot);
        $context->builder->store($finalIdx, $countSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return [$context->builder->load($htSlot), $context->builder->load($countSlot)];
    }

    /**
     * Return the Nth matching element under {@code $root}, or null box.
     *
     * @param bool $descendantsOnly when true (Element::getElementsByTagName), skip
     *        {@code $root} itself and walk from firstChild (#34780 / php-src element.c).
     */
    public static function itemAt(
        Context $context,
        Value $root,
        string $tag,
        Value $indexI64,
        bool $descendantsOnly = false
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_live_tag_item');
        self::ensureLayout($context);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $resultPtr);

        $rootNull = $context->builder->icmp(Builder::INT_EQ, $root, $objPtrTy->constNull());
        $neg = $context->builder->icmp(Builder::INT_SLT, $indexI64, $i64->constInt(0, false));
        $bad = $context->builder->or($rootNull, $neg);
        $bbDone = BasicBlockHelper::append($context, 'dom_live_tag_item_done');
        $bbWalk = BasicBlockHelper::append($context, 'dom_live_tag_item_walk');
        $context->builder->branchIf($bad, $bbDone, $bbWalk);

        $context->builder->positionAtEnd($bbWalk);
        $start = $root;
        if ($descendantsOnly) {
            $start = self::loadFirstChildObject($context, $root);
            $startNull = $context->builder->icmp(Builder::INT_EQ, $start, $objPtrTy->constNull());
            $bbFind = BasicBlockHelper::append($context, 'dom_live_tag_item_find');
            $context->builder->branchIf($startNull, $bbDone, $bbFind);
            $context->builder->positionAtEnd($bbFind);
        }
        $found = self::emitPreorderFind($context, $start, $root, $tag, $indexI64);
        $foundNull = $context->builder->icmp(Builder::INT_EQ, $found, $objPtrTy->constNull());
        $bbWrite = BasicBlockHelper::append($context, 'dom_live_tag_item_write');
        $context->builder->branchIf($foundNull, $bbDone, $bbWrite);
        $context->builder->positionAtEnd($bbWrite);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $resultPtr,
            $found
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return JitValueBox::normalizeValuePtr($context, $resultPtr);
    }

    /** Load PROP_FIRST_CHILD as __object__* or null. */
    private static function loadFirstChildObject(Context $context, Value $parent): Value
    {
        $objectType = $context->type->object;
        $objPtrTy = $context->getTypeFromString('__object__*');
        $voidPtr = $context->getTypeFromString('void*');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $outSlot = BasicBlockHelper::entryAlloca($context, $objPtrTy);
        $context->builder->store($objPtrTy->constNull(), $outSlot);
        $raw = $context->builder->load(
            $objectType->propertySlotFor($parent, 'DOMElement', VmDom::PROP_FIRST_CHILD)
        );
        $slotNull = $context->builder->icmp(Builder::INT_EQ, $raw, $voidPtr->constNull());
        $bbRead = BasicBlockHelper::append($context, 'dom_live_tag_first_read');
        $bbDone = BasicBlockHelper::append($context, 'dom_live_tag_first_done');
        $context->builder->branchIf($slotNull, $bbDone, $bbRead);
        $context->builder->positionAtEnd($bbRead);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($raw, $valuePtrTy)
        );
        $context->builder->store($obj, $outSlot);
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($outSlot);
    }

    private static function ensureLayout(Context $context): void
    {
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([
            VmDom::PROP_FIRST_CHILD,
            VmDom::PROP_NEXT_SIBLING,
            VmDom::PROP_PARENT_NODE,
        ] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        foreach ([VmDom::PROP_TAG_NAME, VmDom::PROP_NODE_NAME] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_STRING);
            }
        }
    }

    /**
     * Preorder collect matching elements into {@code $ht} at successive indices.
     */
    private static function emitPreorderCollect(
        Context $context,
        Value $root,
        string $tag,
        Value $ht,
        Value $idxSlot
    ): void {
        $objPtrTy = $context->getTypeFromString('__object__*');
        $sizeT = $context->getTypeFromString('size_t');

        $curSlot = BasicBlockHelper::entryAlloca($context, $objPtrTy);
        $context->builder->store($root, $curSlot);

        $bbHdr = BasicBlockHelper::append($context, 'dom_live_tag_col_hdr');
        $bbBody = BasicBlockHelper::append($context, 'dom_live_tag_col_body');
        $bbNext = BasicBlockHelper::append($context, 'dom_live_tag_col_next');
        $bbEnd = BasicBlockHelper::append($context, 'dom_live_tag_col_end');
        $context->builder->branch($bbHdr);

        $context->builder->positionAtEnd($bbHdr);
        $cur = $context->builder->load($curSlot);
        $curNull = $context->builder->icmp(Builder::INT_EQ, $cur, $objPtrTy->constNull());
        $context->builder->branchIf($curNull, $bbEnd, $bbBody);

        $context->builder->positionAtEnd($bbBody);
        $matches = self::emitNodeMatchesTag($context, $cur, $tag);
        $bbEmit = BasicBlockHelper::append($context, 'dom_live_tag_col_emit');
        $bbAfterEmit = BasicBlockHelper::append($context, 'dom_live_tag_col_after_emit');
        $context->builder->branchIf($matches, $bbEmit, $bbAfterEmit);

        $context->builder->positionAtEnd($bbEmit);
        $boxSlot = JitValueBox::alloc($context);
        $boxPtr = JitValueBox::pointer($context, $boxSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $boxPtr,
            $cur
        );
        $elem = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $boxPtr)
        );
        $idx = $context->builder->load($idxSlot);
        HashTableHelper::setAtIndex($context, $ht, $idx, $elem);
        $context->builder->store(
            $context->builder->add($idx, $sizeT->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($bbAfterEmit);

        $context->builder->positionAtEnd($bbAfterEmit);
        $context->builder->branch($bbNext);

        $context->builder->positionAtEnd($bbNext);
        $next = self::emitNextPreorder($context, $cur, $root);
        $context->builder->store($next, $curSlot);
        $context->builder->branch($bbHdr);

        $context->builder->positionAtEnd($bbEnd);
    }

    /**
     * Preorder find: return object at matching index, or null.
     *
     * @param Value $start first node to visit
     * @param Value $boundary climb stop (context element / documentElement)
     */
    private static function emitPreorderFind(
        Context $context,
        Value $start,
        Value $boundary,
        string $tag,
        Value $indexI64
    ): Value {
        $objPtrTy = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');

        $curSlot = BasicBlockHelper::entryAlloca($context, $objPtrTy);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $foundSlot = BasicBlockHelper::entryAlloca($context, $objPtrTy);
        $context->builder->store($start, $curSlot);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $context->builder->store($objPtrTy->constNull(), $foundSlot);

        $bbHdr = BasicBlockHelper::append($context, 'dom_live_tag_find_hdr');
        $bbBody = BasicBlockHelper::append($context, 'dom_live_tag_find_body');
        $bbNext = BasicBlockHelper::append($context, 'dom_live_tag_find_next');
        $bbEnd = BasicBlockHelper::append($context, 'dom_live_tag_find_end');
        $context->builder->branch($bbHdr);

        $context->builder->positionAtEnd($bbHdr);
        $cur = $context->builder->load($curSlot);
        $curNull = $context->builder->icmp(Builder::INT_EQ, $cur, $objPtrTy->constNull());
        $context->builder->branchIf($curNull, $bbEnd, $bbBody);

        $context->builder->positionAtEnd($bbBody);
        $matches = self::emitNodeMatchesTag($context, $cur, $tag);
        $bbMaybe = BasicBlockHelper::append($context, 'dom_live_tag_find_maybe');
        $context->builder->branchIf($matches, $bbMaybe, $bbNext);

        $context->builder->positionAtEnd($bbMaybe);
        $idx = $context->builder->load($idxSlot);
        $at = $context->builder->icmp(Builder::INT_EQ, $idx, $indexI64);
        $bbHit = BasicBlockHelper::append($context, 'dom_live_tag_find_hit');
        $bbInc = BasicBlockHelper::append($context, 'dom_live_tag_find_inc');
        $context->builder->branchIf($at, $bbHit, $bbInc);

        $context->builder->positionAtEnd($bbHit);
        $context->builder->store($cur, $foundSlot);
        $context->builder->branch($bbEnd);

        $context->builder->positionAtEnd($bbInc);
        $context->builder->store(
            $context->builder->add($idx, $i64->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($bbNext);

        $context->builder->positionAtEnd($bbNext);
        $next = self::emitNextPreorder($context, $cur, $boundary);
        $context->builder->store($next, $curSlot);
        $context->builder->branch($bbHdr);

        $context->builder->positionAtEnd($bbEnd);

        return $context->builder->load($foundSlot);
    }

    /**
     * Next node in document order within the subtree rooted at {@code $root}.
     */
    private static function emitNextPreorder(
        Context $context,
        Value $cur,
        Value $root
    ): Value {
        $objectType = $context->type->object;
        $objPtrTy = $context->getTypeFromString('__object__*');
        $voidPtr = $context->getTypeFromString('void*');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $outSlot = BasicBlockHelper::entryAlloca($context, $objPtrTy);
        $context->builder->store($objPtrTy->constNull(), $outSlot);

        $bbTryChild = BasicBlockHelper::append($context, 'dom_live_tag_nx_child');
        $bbClimb = BasicBlockHelper::append($context, 'dom_live_tag_nx_climb');
        $bbDone = BasicBlockHelper::append($context, 'dom_live_tag_nx_done');

        // Prefer firstChild.
        $context->builder->branch($bbTryChild);
        $context->builder->positionAtEnd($bbTryChild);
        $firstRaw = $context->builder->load(
            $objectType->propertySlotFor($cur, 'DOMElement', VmDom::PROP_FIRST_CHILD)
        );
        $firstSlotNull = $context->builder->icmp(Builder::INT_EQ, $firstRaw, $voidPtr->constNull());
        $bbReadFirst = BasicBlockHelper::append($context, 'dom_live_tag_nx_read_first');
        $context->builder->branchIf($firstSlotNull, $bbClimb, $bbReadFirst);
        $context->builder->positionAtEnd($bbReadFirst);
        $firstObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($firstRaw, $valuePtrTy)
        );
        $firstObjNull = $context->builder->icmp(Builder::INT_EQ, $firstObj, $objPtrTy->constNull());
        $bbTakeFirst = BasicBlockHelper::append($context, 'dom_live_tag_nx_take_first');
        $context->builder->branchIf($firstObjNull, $bbClimb, $bbTakeFirst);
        $context->builder->positionAtEnd($bbTakeFirst);
        $context->builder->store($firstObj, $outSlot);
        $context->builder->branch($bbDone);

        // Climb for nextSibling; stop at root.
        $context->builder->positionAtEnd($bbClimb);
        $climbSlot = BasicBlockHelper::entryAlloca($context, $objPtrTy);
        $context->builder->store($cur, $climbSlot);
        $bbClimbHdr = BasicBlockHelper::append($context, 'dom_live_tag_nx_climb_hdr');
        $bbClimbBody = BasicBlockHelper::append($context, 'dom_live_tag_nx_climb_body');
        $context->builder->branch($bbClimbHdr);

        $context->builder->positionAtEnd($bbClimbHdr);
        $climb = $context->builder->load($climbSlot);
        $atRoot = $context->builder->icmp(Builder::INT_EQ, $climb, $root);
        $climbNull = $context->builder->icmp(Builder::INT_EQ, $climb, $objPtrTy->constNull());
        $stop = $context->builder->or($atRoot, $climbNull);
        $context->builder->branchIf($stop, $bbDone, $bbClimbBody);

        $context->builder->positionAtEnd($bbClimbBody);
        $nextRaw = $context->builder->load(
            $objectType->propertySlotFor($climb, 'DOMElement', VmDom::PROP_NEXT_SIBLING)
        );
        $nextSlotNull = $context->builder->icmp(Builder::INT_EQ, $nextRaw, $voidPtr->constNull());
        $bbReadNext = BasicBlockHelper::append($context, 'dom_live_tag_nx_read_sib');
        $bbGoParent = BasicBlockHelper::append($context, 'dom_live_tag_nx_parent');
        $context->builder->branchIf($nextSlotNull, $bbGoParent, $bbReadNext);

        $context->builder->positionAtEnd($bbReadNext);
        $nextObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($nextRaw, $valuePtrTy)
        );
        $nextObjNull = $context->builder->icmp(Builder::INT_EQ, $nextObj, $objPtrTy->constNull());
        $bbTakeNext = BasicBlockHelper::append($context, 'dom_live_tag_nx_take_sib');
        $context->builder->branchIf($nextObjNull, $bbGoParent, $bbTakeNext);
        $context->builder->positionAtEnd($bbTakeNext);
        $context->builder->store($nextObj, $outSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbGoParent);
        $parentRaw = $context->builder->load(
            $objectType->propertySlotFor($climb, 'DOMElement', VmDom::PROP_PARENT_NODE)
        );
        $parentSlotNull = $context->builder->icmp(Builder::INT_EQ, $parentRaw, $voidPtr->constNull());
        $bbReadParent = BasicBlockHelper::append($context, 'dom_live_tag_nx_read_parent');
        $context->builder->branchIf($parentSlotNull, $bbDone, $bbReadParent);
        $context->builder->positionAtEnd($bbReadParent);
        $parentObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($parentRaw, $valuePtrTy)
        );
        $context->builder->store($parentObj, $climbSlot);
        $context->builder->branch($bbClimbHdr);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($outSlot);
    }

    /** i1: node is a DOMElement matching {@code $tag} ({@code *} / '' = any element). */
    private static function emitNodeMatchesTag(Context $context, Value $node, string $tag): Value
    {
        $objectType = $context->type->object;
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $strPtr = $context->getTypeFromString('__string__*');

        $elementId = $objectType->lookup('DOMElement');
        $livingId = $objectType->lookup('Dom\\Element');
        $map = $context->structFieldMap['__object__'];
        $classIdVal = $context->builder->load(
            $context->builder->structGep($node, $map['class_id'])
        );
        $isClassic = $context->builder->icmp(
            Builder::INT_EQ,
            $classIdVal,
            $i64->constInt($elementId, false)
        );
        $isLiving = $context->builder->icmp(
            Builder::INT_EQ,
            $classIdVal,
            $i64->constInt($livingId, false)
        );
        $isElement = $context->builder->or($isClassic, $isLiving);

        $outSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i1->constInt(0, false), $outSlot);
        $bbNotElem = BasicBlockHelper::append($context, 'dom_live_tag_match_no');
        $bbElem = BasicBlockHelper::append($context, 'dom_live_tag_match_elem');
        $bbDone = BasicBlockHelper::append($context, 'dom_live_tag_match_done');
        $context->builder->branchIf($isElement, $bbElem, $bbNotElem);

        $context->builder->positionAtEnd($bbNotElem);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbElem);
        $tagLc = strtolower($tag);
        // php-src nodelist.c: getElementsByTagName returns ELEMENT nodes only — thin-AOT
        // #text/#comment stand-ins use DOMElement allocations (#33918 deep importNode).
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_NODE_NAME)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_NODE_NAME, JITVariable::TYPE_STRING);
        }
        $nameVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $node,
            'DOMElement',
            VmDom::PROP_NODE_NAME,
            $elementClassId
        );
        $nameStr = $context->helper->loadValue($nameVar);
        $isRealElement = self::emitNodeNameIsElement($context, $nameStr);
        $bbReal = BasicBlockHelper::append($context, 'dom_live_tag_match_real');
        $context->builder->branchIf($isRealElement, $bbReal, $bbDone);
        $context->builder->positionAtEnd($bbReal);
        if ('*' === $tagLc || '' === $tagLc) {
            $context->builder->store($i1->constInt(1, false), $outSlot);
            $context->builder->branch($bbDone);
        } else {
            $tagStr = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $node,
                'DOMElement',
                VmDom::PROP_TAG_NAME,
                $elementClassId
            );
            $tagStrVal = $context->helper->loadValue($tagStr);
            $tagNull = $context->builder->icmp(Builder::INT_EQ, $tagStrVal, $strPtr->constNull());
            $bbCmp = BasicBlockHelper::append($context, 'dom_live_tag_match_cmp');
            $context->builder->branchIf($tagNull, $bbDone, $bbCmp);
            $context->builder->positionAtEnd($bbCmp);
            $want = $context->builder->load($context->constantStringFromString($tagLc));
            $cmp = JitStringCompare::strcmp($context, $tagStrVal, $want);
            $eqTag = $context->builder->icmp(Builder::INT_EQ, $cmp, $i64->constInt(0, false));
            // php-src nodelist.c / xmlNode->name: match local name so `<x:a>`
            // satisfies getElementsByTagName('a') (#34936).
            if (!$objectType->hasProperty($elementClassId, VmDom::PROP_LOCAL_NAME)) {
                $objectType->defineProperty($elementClassId, VmDom::PROP_LOCAL_NAME, JITVariable::TYPE_STRING);
            }
            $localVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
                $objectType,
                $node,
                'DOMElement',
                VmDom::PROP_LOCAL_NAME,
                $elementClassId
            );
            $localStr = $context->helper->loadValue($localVar);
            $localNull = $context->builder->icmp(Builder::INT_EQ, $localStr, $strPtr->constNull());
            $cmpLocal = JitStringCompare::strcmp($context, $localStr, $want);
            $eqLocal = $context->builder->icmp(Builder::INT_EQ, $cmpLocal, $i64->constInt(0, false));
            $eqLocal = $context->builder->select($localNull, $i1->constInt(0, false), $eqLocal);
            $eq = $context->builder->or($eqTag, $eqLocal);
            $context->builder->store($eq, $outSlot);
            $context->builder->branch($bbDone);
        }

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($outSlot);
    }

    /**
     * i1: {@code nodeName} is an Element node, not a #text/#comment stand-in (#33918).
     */
    private static function emitNodeNameIsElement(Context $context, Value $nameStr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i1->constInt(0, false);
        $one = $i1->constInt(1, false);
        $nonElements = [
            '#text',
            '#comment',
            '#cdata-section',
            '#document-fragment',
            '#document-type',
            '#entity-ref',
        ];
        $isNon = $zero;
        foreach ($nonElements as $lit) {
            $want = $context->builder->load($context->constantStringFromString($lit));
            $cmp = JitStringCompare::strcmp($context, $nameStr, $want);
            $match = $context->builder->icmp(Builder::INT_EQ, $cmp, $i64->constInt(0, false));
            $isNon = $context->builder->or($isNon, $match);
        }

        return $context->builder->select($isNon, $zero, $one);
    }
}
