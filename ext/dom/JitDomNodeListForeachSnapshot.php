<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT foreach over DOMNodeList via snapshot → hashtable (#32707, #33082, #33645, #33659).
 *
 * Iterator protocol and runtime item() inside the foreach CFG both break module
 * verification or abort under nested JIT. For user-script getElementsByTagName lists,
 * prefer a **live** document-order walk from the pinned documentElement (#33659) —
 * compile-time loadXML rematerialization (#32707 / #27275) misses createElement nodes
 * linked via LiveSlots. Fall back to compile-time XML when the root is unset.
 * For {@code $el->childNodes} (no tag query), snapshot **live**
 * {@code owner.firstChild→nextSibling} at Iterator_Reset (#33645) — a compile-time
 * loadXML child list (#33082) ignored after/before/append/prepend. For
 * {@code $el->attributes} NamedNodeMap, snapshot root open-tag attributes (#33099).
 *
 * php-src: ext/dom/nodelist.c / namednodemap.c — InternalIterator; Zend copies
 * non-array Traversables when foreach stability requires a snapshot.
 */
final class JitDomNodeListForeachSnapshot
{
    /** Set when thin-AOT last fetched DOMNode::$childNodes (#33082). */
    private static bool $lastChildNodesFetch = false;

    /** Set when thin-AOT last fetched DOMElement::$attributes (#33099). */
    private static bool $lastAttributesFetch = false;

    public static function markChildNodesFetch(): void
    {
        self::$lastChildNodesFetch = true;
        self::$lastAttributesFetch = false;
        // Child list is not a tag query — clear so canLower prefers direct children.
        JitDomGetElementsByTagNameUserScript::clearTagQueryState();
        JitDomXPathQueryUserScript::clearQueryState();
    }

    public static function markAttributesFetch(): void
    {
        self::$lastAttributesFetch = true;
        self::$lastChildNodesFetch = false;
        JitDomGetElementsByTagNameUserScript::clearTagQueryState();
        JitDomXPathQueryUserScript::clearQueryState();
    }

    public static function clearChildNodesFetch(): void
    {
        self::$lastChildNodesFetch = false;
    }

    public static function clearAttributesFetch(): void
    {
        self::$lastAttributesFetch = false;
    }

    public static function lastWasChildNodesFetch(): bool
    {
        return self::$lastChildNodesFetch;
    }

    public static function lastWasAttributesFetch(): bool
    {
        return self::$lastAttributesFetch;
    }

    public static function isDomNodeListForeach(?string $containerUserType): bool
    {
        if (self::$lastChildNodesFetch || self::$lastAttributesFetch) {
            return true;
        }
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }
        $lc = strtolower(ltrim($containerUserType, '\\'));

        return 'domnodelist' === $lc
            || 'domnamednodemap' === $lc
            || 'dom\\nodelist' === $lc
            || 'dom\\htmlcollection' === $lc
            || 'dom\\dtdnamednodemap' === $lc
            || 'dom\\namednodemap' === $lc;
    }

    /** True for DOMNodeList / HTMLCollection — not NamedNodeMap (attrs need a different bake). */
    public static function isChildNodesStyleList(?string $containerUserType): bool
    {
        if (self::$lastChildNodesFetch) {
            return true;
        }
        if (self::$lastAttributesFetch) {
            return false;
        }
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }
        $lc = strtolower(ltrim($containerUserType, '\\'));

        return 'domnodelist' === $lc
            || 'dom\\nodelist' === $lc
            || 'dom\\htmlcollection' === $lc;
    }

    public static function isNamedNodeMapStyleList(?string $containerUserType): bool
    {
        if (self::$lastAttributesFetch) {
            return true;
        }
        if (null === $containerUserType || '' === $containerUserType) {
            return false;
        }
        $lc = strtolower(ltrim($containerUserType, '\\'));

        return 'domnamednodemap' === $lc
            || 'dom\\namednodemap' === $lc
            || 'dom\\dtdnamednodemap' === $lc;
    }

    public static function canLower(
        Context $context,
        JITVariable $array,
        ?string $containerUserType = null
    ): bool {
        if (!JitDomLoadHTMLUserScript::shouldUse($context)) {
            return false;
        }
        $tag = JitDomGetElementsByTagNameUserScript::lastTagQuery();
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml()
            ?? JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml();
        if (null !== $tag && null !== $xml) {
            return true;
        }
        $xpathTag = JitDomXPathQueryUserScript::lastQueryTag();
        if (null !== $xml && null !== $xpathTag && '' !== $xpathTag) {
            return true;
        }
        if (null === $xml) {
            return false;
        }
        // attributes NamedNodeMap (#33099) or childNodes / HTMLCollection (#33082).
        return self::isNamedNodeMapStyleList($containerUserType)
            || self::isChildNodesStyleList($containerUserType);
    }

    public static function compileReset(
        Context $context,
        JITVariable $array,
        JITVariable $slotKey,
        ?string $containerUserType = null
    ): void {
        if (!self::canLower($context, $array, $containerUserType)) {
            throw new \LogicException(
                'DOMNodeList foreach in thin AOT requires compile-time getElementsByTagName/loadXML (#32707/#33082/#33099)'
            );
        }

        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml()
            ?? JitDomLoadHTMLUserScript::lastCompileTimeParsedHtml();
        $tag = JitDomGetElementsByTagNameUserScript::lastTagQuery();
        $xpathTag = JitDomXPathQueryUserScript::lastQueryTag();
        if (null === $tag && null !== $xpathTag) {
            $tag = $xpathTag;
        }
        if (null === $xml) {
            throw new \LogicException('DOMNodeList foreach snapshot missing compile-time XML (#32707/#33082/#33099)');
        }

        $sizeT = $context->getTypeFromString('size_t');
        $attrsMode = self::isNamedNodeMapStyleList($containerUserType);
        $useDirectChildren = !$attrsMode && (
            self::$lastChildNodesFetch
            || null === $tag
            || '' === $tag
        );

        if ($attrsMode) {
            $attrs = DomParseSimpleXmlJitHelper::rootAttributesArgv($xml);
            $count = \count($attrs);
            $ht = HashTableHelper::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__hashtable__grow'),
                $ht,
                $sizeT->constInt($count > 0 ? $count : 1, false)
            );
            for ($i = 0; $i < $count; ++$i) {
                $itemPtr = JitDomNodeListItemUserScript::materializeRootAttrAtCompileTime(
                    $context,
                    $xml,
                    $i
                );
                $elem = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $itemPtr);
                HashTableHelper::setAtIndex($context, $ht, $sizeT->constInt($i, false), $elem);
            }
            $countVal = $sizeT->constInt($count, false);
        } elseif (!$useDirectChildren) {
            // Live document-order walk when pinned root exists (#33659).
            [$ht, $countVal] = self::emitLiveTagListSnapshot($context, (string) $tag, $xml);
        } else {
            // Live childNodes: owner.firstChild→nextSibling at Reset (#33645).
            // Compile-time loadXML children (#33082) ignored after/before/append/prepend.
            [$ht, $countVal] = self::emitLiveChildNodesSnapshot($context, $array, $xml);
        }

        $map = $context->structFieldMap['__hashtable__'];
        $context->builder->store($countVal, $context->builder->structGep($ht, $map['numElements']));
        $context->builder->store($countVal, $context->builder->structGep($ht, $map['nextFreeElement']));

        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $context->foreachDomNodeListSlots[$context->foreachSlotMapKey($slotKey)] = $htVar;
        self::clearChildNodesFetch();
        self::clearAttributesFetch();

        if (!isset($context->foreachIndexSlots[$context->foreachSlotMapKey($slotKey)])) {
            $context->foreachIndexSlots[$context->foreachSlotMapKey($slotKey)] = BasicBlockHelper::entryAlloca($context, $sizeT);
        }
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $invalid = $context->builder->sub($zero, $one);
        $context->builder->store($invalid, $context->foreachIndexSlots[$context->foreachSlotMapKey($slotKey)]);
    }

    /**
     * Pack getElementsByTagName matches into a foreach hashtable (#33659).
     *
     * Prefers live walk from pinned documentElement; falls back to compile-time
     * loadXML rematerialization when the root is unset.
     *
     * @return array{0: Value, 1: Value} __hashtable__* and size_t element count
     */
    private static function emitLiveTagListSnapshot(
        Context $context,
        string $tag,
        string $xml
    ): array {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nl_foreach_tag');
        $sizeT = $context->getTypeFromString('size_t');
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $objPtrTy = $context->getTypeFromString('__object__*');

        $htSlot = BasicBlockHelper::entryAlloca($context, $htPtrTy);
        $countSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($htPtrTy->constNull(), $htSlot);
        $context->builder->store($sizeT->constInt(0, false), $countSlot);

        $pinned = DomUserScriptPinnedRootLlvm::load($context);
        $noPin = $context->builder->icmp(Builder::INT_EQ, $pinned, $objPtrTy->constNull());
        $bbFallback = BasicBlockHelper::append($context, 'dom_nl_foreach_tag_fb');
        $bbLive = BasicBlockHelper::append($context, 'dom_nl_foreach_tag_live');
        $bbDone = BasicBlockHelper::append($context, 'dom_nl_foreach_tag_done');
        $context->builder->branchIf($noPin, $bbFallback, $bbLive);

        $context->builder->positionAtEnd($bbFallback);
        $count = DomParseSimpleXmlJitHelper::countTagArgv($xml, $tag);
        $fbHt = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__hashtable__grow'),
            $fbHt,
            $sizeT->constInt($count > 0 ? $count : 1, false)
        );
        for ($i = 0; $i < $count; ++$i) {
            $itemPtr = JitDomNodeListItemUserScript::materializeItemAtCompileTime($context, $xml, $tag, $i);
            $elem = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $itemPtr);
            HashTableHelper::setAtIndex($context, $fbHt, $sizeT->constInt($i, false), $elem);
        }
        $context->builder->store($fbHt, $htSlot);
        $context->builder->store($sizeT->constInt($count, false), $countSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbLive);
        [$liveHt, $liveCount] = JitDomLiveElementsByTagWalk::snapshotToHashtable($context, $pinned, $tag);
        $context->builder->store($liveHt, $htSlot);
        $context->builder->store($liveCount, $countSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return [$context->builder->load($htSlot), $context->builder->load($countSlot)];
    }

    /**
     * Pack live childNodes into a foreach hashtable (#33645).
     *
     * Prefers {@code DOMNodeList} owner + firstChild→nextSibling (matches item()).
     * Falls back to compile-time loadXML children when the list has no owner yet
     * (empty createElement before any child sync).
     *
     * @return array{0: Value, 1: Value} __hashtable__* and size_t element count
     */
    private static function emitLiveChildNodesSnapshot(
        Context $context,
        JITVariable $array,
        string $xml
    ): array {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nl_foreach_live');
        $objectType = $context->type->object;
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $objPtrTy = $context->getTypeFromString('__object__*');
        $htPtrTy = $context->getTypeFromString('__hashtable__*');

        $listClassId = $objectType->lookup('DOMNodeList');
        if (!$objectType->hasProperty($listClassId, 'length')) {
            $objectType->defineProperty($listClassId, 'length', JITVariable::TYPE_NATIVE_LONG);
        }
        if (!$objectType->hasProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER)) {
            $objectType->defineProperty($listClassId, VmDom::PROP_CHILD_NODES_OWNER, JITVariable::TYPE_VALUE);
        }
        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_NEXT_SIBLING] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }

        // Merge fallback / live / empty via entry allocas (avoids ht* phis).
        $htSlot = BasicBlockHelper::entryAlloca($context, $htPtrTy);
        $countSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_nl_foreach_live_slots');
        $context->builder->store($htPtrTy->constNull(), $htSlot);
        $context->builder->store($sizeT->constInt(0, false), $countSlot);

        $list = self::loadNodeListObject($context, $array);
        $ownerSlot = $objectType->propertySlotFor($list, 'DOMNodeList', VmDom::PROP_CHILD_NODES_OWNER);
        $ownerPtr = $context->builder->load($ownerSlot);
        $noOwner = $context->builder->icmp(Builder::INT_EQ, $ownerPtr, $voidPtr->constNull());

        $bbFallback = BasicBlockHelper::append($context, 'dom_nl_foreach_fb_xml');
        $bbLive = BasicBlockHelper::append($context, 'dom_nl_foreach_live_walk');
        $bbDone = BasicBlockHelper::append($context, 'dom_nl_foreach_live_done');
        $context->builder->branchIf($noOwner, $bbFallback, $bbLive);

        // Fallback: original loadXML direct children (#33082) when owner unset.
        $context->builder->positionAtEnd($bbFallback);
        $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
        $fbCount = \count($nodes);
        $fbHt = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__hashtable__grow'),
            $fbHt,
            $sizeT->constInt($fbCount > 0 ? $fbCount : 1, false)
        );
        for ($i = 0; $i < $fbCount; ++$i) {
            $itemPtr = JitDomNodeListItemUserScript::materializeDirectChildAtCompileTime(
                $context,
                $xml,
                $i
            );
            $elem = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $itemPtr);
            HashTableHelper::setAtIndex($context, $fbHt, $sizeT->constInt($i, false), $elem);
        }
        $context->builder->store($fbHt, $htSlot);
        $context->builder->store($sizeT->constInt($fbCount, false), $countSlot);
        $context->builder->branch($bbDone);

        // Live walk: grow from length; pack firstChild→nextSibling.
        $context->builder->positionAtEnd($bbLive);
        $lengthVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $list,
            'DOMNodeList',
            'length',
            $listClassId
        );
        $lengthI64 = $context->helper->loadValue($lengthVar);
        $lengthSz = $context->builder->intCast($lengthI64, $sizeT);
        $growOne = $sizeT->constInt(1, false);
        $growNeed = $context->builder->icmp(Builder::INT_UGT, $lengthSz, $growOne);
        $growN = $context->builder->select($growNeed, $lengthSz, $growOne);

        $liveHt = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__hashtable__grow'),
            $liveHt,
            $growN
        );
        $context->builder->store($liveHt, $htSlot);

        $owner = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($ownerPtr, $valuePtrTy)
        );
        $ownerNull = $context->builder->icmp(Builder::INT_EQ, $owner, $objPtrTy->constNull());
        $bbEmpty = BasicBlockHelper::append($context, 'dom_nl_foreach_live_empty');
        $bbReadFirst = BasicBlockHelper::append($context, 'dom_nl_foreach_live_first');
        $context->builder->branchIf($ownerNull, $bbEmpty, $bbReadFirst);

        $context->builder->positionAtEnd($bbEmpty);
        $context->builder->store($sizeT->constInt(0, false), $countSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbReadFirst);
        $firstRaw = $context->builder->load(
            $objectType->propertySlotFor($owner, 'DOMElement', VmDom::PROP_FIRST_CHILD)
        );
        $firstSlotNull = $context->builder->icmp(Builder::INT_EQ, $firstRaw, $voidPtr->constNull());
        $bbEnter = BasicBlockHelper::append($context, 'dom_nl_foreach_live_enter');
        $context->builder->branchIf($firstSlotNull, $bbEmpty, $bbEnter);

        $context->builder->positionAtEnd($bbEnter);
        $firstObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($firstRaw, $valuePtrTy)
        );
        $firstObjNull = $context->builder->icmp(Builder::INT_EQ, $firstObj, $objPtrTy->constNull());
        $bbLoopHdr = BasicBlockHelper::append($context, 'dom_nl_foreach_live_hdr');
        $context->builder->branchIf($firstObjNull, $bbEmpty, $bbLoopHdr);

        $bbBody = BasicBlockHelper::append($context, 'dom_nl_foreach_live_body');
        $bbAdvance = BasicBlockHelper::append($context, 'dom_nl_foreach_live_adv');
        $bbFinish = BasicBlockHelper::append($context, 'dom_nl_foreach_live_finish');

        $context->builder->positionAtEnd($bbLoopHdr);
        $curPhi = $context->builder->phi($objPtrTy);
        $idxPhi = $context->builder->phi($sizeT);
        // Always take body when we entered with a non-null node.
        $context->builder->branch($bbBody);

        $context->builder->positionAtEnd($bbBody);
        $boxSlot = JitValueBox::alloc($context);
        $boxPtr = JitValueBox::pointer($context, $boxSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $boxPtr,
            $curPhi
        );
        $elem = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $boxPtr)
        );
        HashTableHelper::setAtIndex($context, $liveHt, $idxPhi, $elem);
        $context->builder->branch($bbAdvance);

        $context->builder->positionAtEnd($bbAdvance);
        $nextRaw = $context->builder->load(
            $objectType->propertySlotFor($curPhi, 'DOMElement', VmDom::PROP_NEXT_SIBLING)
        );
        $nextSlotNull = $context->builder->icmp(Builder::INT_EQ, $nextRaw, $voidPtr->constNull());
        $bbReadNext = BasicBlockHelper::append($context, 'dom_nl_foreach_live_read_next');
        $context->builder->branchIf($nextSlotNull, $bbFinish, $bbReadNext);

        $context->builder->positionAtEnd($bbReadNext);
        $nextObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $context->builder->pointerCast($nextRaw, $valuePtrTy)
        );
        $nextObjNull = $context->builder->icmp(Builder::INT_EQ, $nextObj, $objPtrTy->constNull());
        $bbBack = BasicBlockHelper::append($context, 'dom_nl_foreach_live_back');
        $context->builder->branchIf($nextObjNull, $bbFinish, $bbBack);

        $context->builder->positionAtEnd($bbBack);
        $nextIdx = $context->builder->add($idxPhi, $sizeT->constInt(1, false));
        $context->builder->branch($bbLoopHdr);

        $curPhi->addIncoming($firstObj, $bbEnter);
        $curPhi->addIncoming($nextObj, $bbBack);
        $idxPhi->addIncoming($sizeT->constInt(0, false), $bbEnter);
        $idxPhi->addIncoming($nextIdx, $bbBack);

        $context->builder->positionAtEnd($bbFinish);
        // Count = last written index + 1.
        $finalCount = $context->builder->add($idxPhi, $sizeT->constInt(1, false));
        $context->builder->store($finalCount, $countSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $htOut = $context->builder->load($htSlot);
        $countOut = $context->builder->load($countSlot);

        return [$htOut, $countOut];
    }

    private static function loadNodeListObject(Context $context, JITVariable $array): Value
    {
        if (JITVariable::TYPE_OBJECT === $array->type) {
            return $context->helper->loadValue($array);
        }
        if (JITVariable::TYPE_VALUE === $array->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $array)
            );
        }

        throw new \LogicException('DOMNodeList foreach receiver must be an object (#33645)');
    }
}
