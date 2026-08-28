<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomNodeLiveMutationRuntime;
use PHPCompiler\JIT\Builtin\DomNodeTreeMutationRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::replaceChild() (#19240, #22678, #27216, #28671, #34590, #34709).
 *
 * Thin standalone AOT materializes createElement nodes without DomRegistry
 * ({@see JitDomCreateElement::materializeElementFromLiteral}). The NestedJIT
 * DomRegistry bridge then sees unregistered objects and segfaults — mirror the
 * ParentNode::append LLVM slot sync instead (php-src ext/dom/node.c).
 * Attr as newChild: Hierarchy Request Error — Attr is not content (#33587).
 * Live getElementsByTagName count: dec old + inc new (#34590 / peer #33679).
 * Identity replace (new==old): no-op — must not clear parent/sibling (#34709).
 */
final class JitDomReplaceChild
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMNode::replaceChild() expects receiver, newChild, oldChild');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replace_child_cont');

        if (JitDomRequireDomNodeArg::guardOrAbort($context, $args[1], 'DOMNode::replaceChild', 1, 'node')) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }
        if (JitDomRequireDomNodeArg::guardOrAbort($context, $args[2], 'DOMNode::replaceChild', 2, 'child')) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            // DOMDocument inherits replaceChild from DOMNode; resolveDomSubclass maps to
            // domnode::replacechild. Element LiveSlots on a Document SIGSEGV (#33379).
            // Receiver classUserType is often empty after loadXML — branch on class_id.
            $parentObj = self::loadObjectArg($context, $args[0]);
            $resultSlot = BasicBlockHelper::entryAlloca(
                $context,
                $context->getTypeFromString('__value__*')
            );
            $bbDoc = BasicBlockHelper::append($context, 'dom_rc_as_doc');
            $bbElem = BasicBlockHelper::append($context, 'dom_rc_as_elem');
            $bbEnd = BasicBlockHelper::append($context, 'dom_rc_as_end');
            $context->builder->branchIf(
                self::runtimeIsDocumentObject($context, $parentObj),
                $bbDoc,
                $bbElem
            );

            $context->builder->positionAtEnd($bbDoc);
            $docResult = self::invokeDocumentReplace($context, $args[0], $args[1], $args[2]);
            $context->builder->store($docResult, $resultSlot);
            $context->builder->branch($bbEnd);

            $context->builder->positionAtEnd($bbElem);
            // Snapshot before LiveSlots restamps sticky/lastFetched (#35421).
            self::rememberDetachedChildBeforeLiveSlots($args[0], $args[2]);
            self::syncUserScriptReplaceSlots($context, $args[0], $args[1], $args[2]);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replace_child_post');
            $context->builder->store(
                self::boxObjectResult($context, self::loadObjectArg($context, $args[2])),
                $resultSlot
            );
            $context->builder->branch($bbEnd);

            $context->builder->positionAtEnd($bbEnd);

            // Dual-emit runs invokeDocumentReplace *and* syncUserScriptReplaceSlots at
            // compile time. Do not call refreshCompileTimeXmlReplaceRoot (poisons element
            // saveXML to just <x/> — #33379). syncUserScriptReplaceSlots uses
            // refreshCompileTimeXmlWithRootInner for C14N fold (#32972). Do not
            // markTreeMutated — DomC14NRuntime returns null for LiveSlots without
            // DomRegistry (#32972 / #34666). saveXML already prefers live INNER_XML slots.

            return $context->builder->load($resultSlot);
        }

        DomNodeTreeMutationRuntime::ensureReplaceChildLinked($context);

        $parent = self::loadObjectArg($context, $args[0]);
        $newChild = self::loadObjectArg($context, $args[1]);
        $oldChild = self::loadObjectArg($context, $args[2]);
        // Identity no-op: VmDom short-circuits; do not clearDetachedLinkSlots (#34709 / #22678).
        $bbSame = BasicBlockHelper::append($context, 'dom_rc_nj_identity');
        $bbDiff = BasicBlockHelper::append($context, 'dom_rc_nj_diff');
        $bbEnd = BasicBlockHelper::append($context, 'dom_rc_nj_end');
        $resultSlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__value__*')
        );
        $isSame = $context->builder->icmp(Builder::INT_EQ, $newChild, $oldChild);
        $context->builder->branchIf($isSame, $bbSame, $bbDiff);
        $context->builder->positionAtEnd($bbSame);
        $context->builder->store(self::boxObjectResult($context, $oldChild), $resultSlot);
        $context->builder->branch($bbEnd);
        $context->builder->positionAtEnd($bbDiff);
        $context->builder->call(
            $context->lookupFunction(DomNodeTreeMutationRuntime::ABI_REPLACE_CHILD),
            $parent,
            $newChild,
            $oldChild
        );
        // Null AOT property slots on the replaced node (#19240).
        self::clearDetachedLinkSlots($context, $oldChild);
        $context->builder->store(self::boxObjectResult($context, $oldChild), $resultSlot);
        $context->builder->branch($bbEnd);
        $context->builder->positionAtEnd($bbEnd);

        return $context->builder->load($resultSlot);
    }

    /**
     * Capture pre-mutation oldChild markup for cloneNode on the replaceChild return (#35421).
     * Must run before {@see JitDomReplaceChildLiveSlots::sync} restamps sticky/lastFetched.
     */
    private static function rememberDetachedChildBeforeLiveSlots(
        JITVariable $parentVar,
        JITVariable $oldChildVar
    ): void {
        $xml = $parentVar->compileTimeDomLoadXml
            ?? (JitDomLoadXMLUserScript::lastLoadWasPureUserScript()
                ? JitDomLoadXMLUserScript::lastCompileTimeXml()
                : null);
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            self::rememberDetachedCreateElementChild($oldChildVar);

            return;
        }
        $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
        $index = $oldChildVar->compileTimeDomChildIndex
            ?? JitDomNodeListItem::$lastFetchedChildIndex
            ?? JitDomNodeChildProperty::$lastFetchedChildIndex
            ?? JitDomNodeChildProperty::$stickyChildEdgeChildIndex
            ?? null;
        if (null === $index) {
            $oldTag = $oldChildVar->compileTimeDomTagName
                ?? JitDomNodeListItem::$lastFetchedTagName
                ?? JitDomNodeChildProperty::$lastFetchedTagName
                ?? JitDomNodeChildProperty::$stickyChildEdgeTagName
                ?? null;
            if (null !== $oldTag) {
                foreach ($nodes as $i => $node) {
                    if ('element' === ($node['kind'] ?? '')
                        && strtolower($oldTag) === ($node['data'] ?? null)
                    ) {
                        $index = $i;
                        break;
                    }
                }
            } elseif (1 === \count($nodes)) {
                $index = 0;
            }
        }
        if (null === $index) {
            return;
        }
        $preChunks = DomParseSimpleXmlJitHelper::directChildMarkupChunks(
            DomParseSimpleXmlJitHelper::rootInnerXmlArgv($xml)
        );
        if (!isset($preChunks[$index])) {
            return;
        }
        $parsedOld = DomParseSimpleXmlJitHelper::parseElementMarkupArgv($preChunks[$index]);
        if (null === $parsedOld) {
            return;
        }
        // Dual-emit / second compile-time pass may see a refreshed SSOT; do not
        // overwrite a prior good snapshot with the wrong sibling (#35421).
        $expectTag = $oldChildVar->compileTimeDomTagName
            ?? JitDomNodeChildProperty::$stickyChildEdgeTagName
            ?? JitDomNodeChildProperty::$lastFetchedTagName
            ?? null;
        if (
            null !== $expectTag
            && strtolower($parsedOld['tag']) !== strtolower($expectTag)
        ) {
            return;
        }
        JitDomCloneNode::rememberDetachedChildMarkup($preChunks[$index]);
        $oldChildVar->compileTimeDomTagName = $parsedOld['tag'];
        $oldChildVar->compileTimeDomChildIndex = $index;
        $oldChildVar->compileTimeDomInnerXml = $parsedOld['inner'];
        $oldChildVar->compileTimeDomNodePath = null;
    }

    /**
     * createElement trees (no loadXML SSOT): synthesize detached markup from Variable/sticky
     * tag + attrs — peer {@see JitDomRemoveChild::rememberDetachedChildBeforeLiveSlots} (#35386).
     */
    private static function rememberDetachedCreateElementChild(JITVariable $oldChildVar): void
    {
        $expectTag = $oldChildVar->compileTimeDomTagName
            ?? JitDomNodeChildProperty::$stickyChildEdgeTagName
            ?? JitDomNodeChildProperty::$lastFetchedTagName
            ?? null;
        if (null === $expectTag || '' === $expectTag) {
            return;
        }
        $attrs = '';
        $attrMap = $oldChildVar->compileTimeDomAttributes;
        $id = $oldChildVar->compileTimeDomElementId ?? null;
        if (null === $attrMap || [] === $attrMap) {
            if (null !== $id) {
                $attrMap = JitDomCreateElementAttrs::get($id);
            }
        }
        if (null !== $attrMap && [] !== $attrMap) {
            $attrs = JitDomCreateElementAttrs::formatSuffix($attrMap);
        }
        $inner = $oldChildVar->compileTimeDomInnerXml ?? '';
        $openAttrs = '' === $attrs ? '' : (str_starts_with($attrs, ' ') ? $attrs : ' '.$attrs);
        $markup = '' === $inner
            ? '<'.$expectTag.$openAttrs.'/>'
            : '<'.$expectTag.$openAttrs.'>'.$inner.'</'.$expectTag.'>';
        JitDomCloneNode::rememberDetachedChildMarkup($markup);
        $oldChildVar->compileTimeDomTagName = $expectTag;
        $oldChildVar->compileTimeDomNodePath = null;
    }

    /**
     * Thin-AOT DOMDocument::replaceChild — update documentElement / first+last child (#33379).
     *
     * php-src ext/dom/node.c dom_node_replace_child when parent is document.
     * Peer {@see JitDomAppendChildUserScript::invokeDocumentAppend}.
     */
    public static function invokeDocumentReplace(
        Context $context,
        JITVariable $documentVar,
        JITVariable $newChildVar,
        JITVariable $oldChildVar
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_doc_rc_cont');

        $document = self::loadObjectArg($context, $documentVar);
        $newChild = self::loadObjectArg($context, $newChildVar);
        $oldChild = self::loadObjectArg($context, $oldChildVar);

        // Identity no-op before documentElement rewiring (#34709 / re-#22678).
        $bbSame = BasicBlockHelper::append($context, 'dom_doc_rc_identity');
        $bbDiff = BasicBlockHelper::append($context, 'dom_doc_rc_diff');
        $bbEnd = BasicBlockHelper::append($context, 'dom_doc_rc_end');
        $resultSlot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__value__*')
        );
        $isSame = $context->builder->icmp(Builder::INT_EQ, $newChild, $oldChild);
        $context->builder->branchIf($isSame, $bbSame, $bbDiff);
        $context->builder->positionAtEnd($bbSame);
        $context->builder->store(self::boxObjectResult($context, $oldChild), $resultSlot);
        $context->builder->branch($bbEnd);

        $context->builder->positionAtEnd($bbDiff);
        DomNodeLiveMutationRuntime::assertTreeMutationChildBeforeLiveSlots(
            $context,
            $document,
            $newChild
        );

        $objectType = $context->type->object;
        $newJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newChild);
        $docJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $document);

        $docClassId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($docClassId, VmDom::PROP_DOCUMENT_ELEMENT, JITVariable::TYPE_OBJECT);
        }
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD, VmDom::PROP_CHILD_NODES] as $prop) {
            if (!$objectType->hasProperty($docClassId, $prop)) {
                $objectType->defineProperty($docClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }

        // Install new root (TYPE_OBJECT — loadXML pins documentElement the same way).
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, 'DOMDocument', VmDom::PROP_DOCUMENT_ELEMENT),
            $newJit,
            JITVariable::TYPE_OBJECT
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, 'DOMDocument', VmDom::PROP_FIRST_CHILD),
            $newJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($document, 'DOMDocument', VmDom::PROP_LAST_CHILD),
            $newJit,
            JITVariable::TYPE_VALUE
        );

        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([VmDom::PROP_PARENT_NODE, VmDom::PROP_NEXT_SIBLING, VmDom::PROP_PREVIOUS_SIBLING] as $prop) {
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        $objectType->propertyStore(
            $objectType->propertySlotFor($newChild, 'DOMElement', VmDom::PROP_PARENT_NODE),
            $docJit,
            JITVariable::TYPE_VALUE
        );
        $nullBox = self::nullValueVar($context);
        $objectType->propertyStore(
            $objectType->propertySlotFor($newChild, 'DOMElement', VmDom::PROP_NEXT_SIBLING),
            $nullBox,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($newChild, 'DOMElement', VmDom::PROP_PREVIOUS_SIBLING),
            $nullBox,
            JITVariable::TYPE_VALUE
        );

        self::clearDetachedLinkSlots($context, $oldChild);
        DomUserScriptElementCacheLlvm::clearId($context);
        DomUserScriptPinnedRootLlvm::pin($context, $newChild);

        // Do NOT call refreshCompileTimeXmlReplaceRoot here: dual-path emit lowers this
        // arm even when the runtime parent is a DOMElement, and that helper rewrites
        // every compileTimeDomLoadXml binding to the new root outer (poisoning element
        // replaceChild saveXML). Store InnerXml on the new root and mark mutated so
        // saveXML dumps pinned documentElement slots (#33379 / element peer).
        $newTag = $newChildVar->compileTimeDomTagName ?? null;
        $newInner = $newChildVar->compileTimeDomInnerXml ?? '';
        if (null !== $newTag && '' !== $newTag) {
            JitDomCreateElement::storeUserScriptInnerXml($context, $newChild, $newInner);
        }
        JitDomLoadXMLUserScript::markTreeMutatedSinceLoad();

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_doc_rc_post');
        $context->builder->store(self::boxObjectResult($context, $oldChild), $resultSlot);
        $context->builder->branch($bbEnd);
        $context->builder->positionAtEnd($bbEnd);

        return $context->builder->load($resultSlot);
    }

    /** Runtime: parent object class_id is a Document (#33379). */
    private static function runtimeIsDocumentObject(Context $context, Value $parent): Value
    {
        $objectType = $context->type->object;
        $map = $context->structFieldMap['__object__'];
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $classId = $context->builder->load($context->builder->structGep($parent, $map['class_id']));
        $isDoc = $i1->constInt(0, false);
        foreach (['DOMDocument', 'Dom\\Document', 'Dom\\XMLDocument', 'Dom\\HTMLDocument'] as $className) {
            try {
                $expected = $objectType->lookup($className);
            } catch (\Throwable $e) {
                continue;
            }
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($expected, false)
            );
            $isDoc = $context->builder->or($isDoc, $match);
        }

        return $isDoc;
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

    /**
     * Thin-AOT ChildNode::replaceWith LiveSlots + InnerXml (#32822 / peer #32817).
     *
     * {@see DomNodeChildNodeMutationRuntime} previously only rewrote parent InnerXml
     * to the replacement markup, leaving held childNodes pins stale.
     */
    public static function syncUserScriptReplaceSlotsPublic(
        Context $context,
        JITVariable $parentVar,
        JITVariable $newChildVar,
        JITVariable $oldChildVar
    ): void {
        self::syncUserScriptReplaceSlots($context, $parentVar, $newChildVar, $oldChildVar);
    }

    /**
     * Splice newChild into oldChild's place for thin-AOT replaceChild (#27216 / #28671).
     *
     * Skipping DomRegistry NestedJIT: segfaults on LLVM-materialized createElement
     * nodes. Peer {@see JitDomAppendChildLiveSlots} / {@see JitDomReplaceChildLiveSlots}.
     */
    private static function syncUserScriptReplaceSlots(
        Context $context,
        JITVariable $parentVar,
        JITVariable $newChildVar,
        JITVariable $oldChildVar
    ): void {
        $parent = self::loadObjectArg($context, $parentVar);
        $newChild = self::loadObjectArg($context, $newChildVar);
        $oldChild = self::loadObjectArg($context, $oldChildVar);

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rc_usr_slots');
        $bbEnd = BasicBlockHelper::append($context, 'dom_rc_usr_end');
        // Identity no-op before Attr / LiveSlots / tag-list churn (#34709 / VmDom #22678).
        $bbSame = BasicBlockHelper::append($context, 'dom_rc_usr_identity');
        $bbDiff = BasicBlockHelper::append($context, 'dom_rc_usr_diff');
        $isSame = $context->builder->icmp(Builder::INT_EQ, $newChild, $oldChild);
        $context->builder->branchIf($isSame, $bbSame, $bbDiff);
        $context->builder->positionAtEnd($bbSame);
        $context->builder->branch($bbEnd);

        $context->builder->positionAtEnd($bbDiff);
        // php-src: Attr is not a content child — Hierarchy Request before LiveSlots (#33587).
        // Peer insertBefore throws Error for Attr+ref; replaceChild uses DOMException.
        self::rejectAttrAsContentBeforeLiveSlots($context, $newChild);

        // Wrong Document / Hierarchy Request before LiveSlots (#30274).
        DomNodeLiveMutationRuntime::assertTreeMutationChildBeforeLiveSlots(
            $context,
            $parent,
            $newChild
        );

        $childCount = null;
        // Always defined for syncUserScriptInnerXml — createElement trees never set $xml (#35361).
        $xml = null;
        if (JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            $inner = $parentVar->compileTimeDomInnerXml;
            if (null !== $inner && '' !== $inner) {
                $childCount = \count(DomParseSimpleXmlJitHelper::parseSiblingNodesArgv($inner));
            } else {
                $xml = $parentVar->compileTimeDomLoadXml
                    ?? JitDomLoadXMLUserScript::compileTimeXmlFor($parentVar);
                if (null !== $xml) {
                    $childCount = \count(DomParseSimpleXmlJitHelper::directChildNodesArgv($xml));
                }
            }
        }

        JitDomReplaceChildLiveSlots::sync($context, $parent, $newChild, $oldChild, $childCount);
        // Null parent/sibling slots on detached oldChild — peer document replaceChild (#19240 / #29694).
        self::clearDetachedLinkSlots($context, $oldChild);
        // Drop thin-AOT getElementById cache after detach — single-slot cache may still
        // hold a sibling while xmlAddID keeps the detached node's ID (#29694).
        DomUserScriptElementCacheLlvm::clearId($context);
        // #33659 bumped live tag pending/count on append; remove undoes (#33679).
        // replaceChild must both (#34590) so held getElementsByTagName length matches Zend.
        DomUserScriptLiveTagListLlvm::decrementForChildArg($context, $oldChildVar);
        DomUserScriptLiveTagListLlvm::incrementForChildArg($context, $newChildVar);

        // Element nav slots — only-child replace collapses to newChild (#27216).
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        $newJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newChild);
        if (1 === $childCount) {
            foreach ([VmDom::PROP_FIRST_ELEMENT_CHILD, VmDom::PROP_LAST_ELEMENT_CHILD] as $prop) {
                if (!$objectType->hasProperty($elementClassId, $prop)) {
                    $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
                }
                $objectType->propertyStore(
                    $objectType->propertySlotFor($parent, 'DOMElement', $prop),
                    $newJit,
                    JITVariable::TYPE_VALUE
                );
            }
            if (!$objectType->hasProperty($elementClassId, VmDom::PROP_CHILD_ELEMENT_COUNT)) {
                $objectType->defineProperty(
                    $elementClassId,
                    VmDom::PROP_CHILD_ELEMENT_COUNT,
                    JITVariable::TYPE_NATIVE_LONG
                );
            }
            $countVar = new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $context->getTypeFromString('int64')->constInt(1, false)
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($parent, 'DOMElement', VmDom::PROP_CHILD_ELEMENT_COUNT),
                $countVar,
                JITVariable::TYPE_NATIVE_LONG
            );
        } elseif (null !== $childCount && $childCount > 0) {
            if (!$objectType->hasProperty($elementClassId, VmDom::PROP_CHILD_ELEMENT_COUNT)) {
                $objectType->defineProperty(
                    $elementClassId,
                    VmDom::PROP_CHILD_ELEMENT_COUNT,
                    JITVariable::TYPE_NATIVE_LONG
                );
            }
            $countVar = new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $context->getTypeFromString('int64')->constInt($childCount, false)
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($parent, 'DOMElement', VmDom::PROP_CHILD_ELEMENT_COUNT),
                $countVar,
                JITVariable::TYPE_NATIVE_LONG
            );
        }

        self::syncUserScriptInnerXml($context, $parentVar, $parent, $newChildVar, $oldChildVar, $xml);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rc_usr_diff_done');
        $context->builder->branch($bbEnd);
        $context->builder->positionAtEnd($bbEnd);
    }

    /**
     * Thin-AOT: DOMAttr / Dom\Attr as replaceChild newChild (#33587).
     *
     * php-src dom_node_replace_child rejects Attr (not content). Must not walk
     * Element sibling slots on an Attr allocation (SIGSEGV).
     */
    private static function rejectAttrAsContentBeforeLiveSlots(Context $context, Value $newChild): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_rc_attr_guard');
        $bbAttr = BasicBlockHelper::append($context, 'dom_rc_attr_reject');
        $bbOk = BasicBlockHelper::append($context, 'dom_rc_attr_ok');
        $isAttr = JitDomAppendChildLiveSlots::isAttrNode($context, $newChild);
        $context->builder->branchIf($isAttr, $bbAttr, $bbOk);

        $context->builder->positionAtEnd($bbAttr);
        TryCatchHelper::emitCatchableClassError(
            $context,
            'DOMException',
            'Hierarchy Request Error',
            null,
            '',
            0,
            DomExceptionConstants::HIERARCHY_REQUEST_ERR
        );

        $context->builder->positionAtEnd($bbOk);
    }

    /**
     * Rebuild PROP_USER_SCRIPT_INNER_XML so saveXML keeps non-replaced siblings (#28671).
     *
     * Prior path always stored {@code <newTag/>}, dropping remaining children.
     * createElement-only trees rely on LiveSlots sibling-chain rebuild (#33610).
     */
    private static function syncUserScriptInnerXml(
        Context $context,
        JITVariable $parentVar,
        Value $parent,
        JITVariable $newChildVar,
        JITVariable $oldChildVar,
        ?string $xml
    ): void {
        // Detached markup already snapshotted in rememberDetachedChildBeforeLiveSlots (#35421).
        $newTag = $newChildVar->compileTimeDomTagName ?? null;
        if (null === $newTag || '' === $newTag) {
            return;
        }
        // createElement($name, $value) stamps escaped text on compileTimeDomInnerXml (#32903).
        $newInner = $newChildVar->compileTimeDomInnerXml ?? '';
        // Include setAttribute / importNode open-tag attrs — bare <tag/> overwrote LiveSlots
        // rebuild and dropped attrs from saveXML after loadXML replaceChild (#34291 / peer
        // #33509 / DomNodeLiveMutationRuntime::compileTimeChildElementMarkup).
        $attrs = $newChildVar->compileTimeDomAttributes;
        if (null === $attrs || [] === $attrs) {
            $id = $newChildVar->compileTimeDomElementId ?? JitDomCreateElementAttrs::lastId();
            if (null !== $id) {
                $attrs = JitDomCreateElementAttrs::get($id);
            }
        }
        $attrSuffix = JitDomCreateElementAttrs::formatSuffix($attrs ?? []);
        $replacement = '' === $newInner
            ? '<'.$newTag.$attrSuffix.'/>'
            : '<'.$newTag.$attrSuffix.'>'.$newInner.'</'.$newTag.'>';

        $xml ??= $parentVar->compileTimeDomLoadXml;
        if (null !== $xml && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
            // item($N) ARG_SEND temps often drop compileTimeDomChildIndex (#32903).
            $index = $oldChildVar->compileTimeDomChildIndex
                ?? JitDomNodeListItem::$lastFetchedChildIndex
                ?? JitDomNodeChildProperty::$lastFetchedChildIndex
                ?? JitDomNodeChildProperty::$stickyChildEdgeChildIndex
                ?? null;
            if (null === $index) {
                $oldTag = $oldChildVar->compileTimeDomTagName
                    ?? JitDomNodeListItem::$lastFetchedTagName
                    ?? JitDomNodeChildProperty::$lastFetchedTagName
                    ?? JitDomNodeChildProperty::$stickyChildEdgeTagName
                    ?? null;
                if (null !== $oldTag) {
                    foreach ($nodes as $i => $node) {
                        if ('element' === $node['kind'] && strtolower($oldTag) === $node['data']) {
                            $index = $i;
                            break;
                        }
                    }
                } elseif (1 === \count($nodes)) {
                    $index = 0;
                }
            }
            if (null !== $index) {
                // Same-parent move: newChild is already among the compile-time children.
                // Replacing old's slot with <new/> leaves a duplicate of new (#34806).
                // LiveSlots already rebuilt INNER_XML from the sibling chain.
                $newAlreadyChild = false;
                foreach ($nodes as $i => $node) {
                    if ($i === $index) {
                        continue;
                    }
                    if ('element' === ($node['kind'] ?? '')
                        && strtolower($newTag) === ($node['data'] ?? null)
                    ) {
                        $newAlreadyChild = true;
                        break;
                    }
                }
                if (null !== ($newChildVar->compileTimeDomChildIndex ?? null)
                    || $newAlreadyChild
                ) {
                    return;
                }
                // Detached markup already snapshotted in rememberDetachedChildBeforeLiveSlots (#35421).
                $inner = DomParseSimpleXmlJitHelper::rootInnerXmlReplaceChildAt($xml, $index, $replacement);
                if (null !== $inner) {
                    JitDomCreateElement::storeUserScriptInnerXml($context, $parent, $inner);
                    JitDomLoadXMLUserScript::refreshCompileTimeXmlWithRootInner($inner, $parentVar);

                    return;
                }
            }
            // Multi-child without proven index: leave seeded INNER_XML (do not collapse).

            return;
        }

        // createElement-only trees: LiveSlots already rebuilt INNER_XML from the
        // sibling chain ({@see JitDomReplaceChildLiveSlots::syncNonFragment}).
        // Storing only <newTag/> here collapsed multi-child saveXML to the
        // replacement alone (#33610). Only-child trees still match Zend via that
        // rebuild (legacy #27216).
    }

    /** Null parent/sibling LLVM slots on the detached node (ext/dom/node.c; #19240). */
    private static function clearDetachedLinkSlots(Context $context, Value $node): void
    {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        foreach ([VmDom::PROP_PARENT_NODE, VmDom::PROP_NEXT_SIBLING, VmDom::PROP_PREVIOUS_SIBLING] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        $nullSlot = JitValueBox::alloc($context);
        $nullPtr = JitValueBox::pointer($context, $nullSlot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);
        $nullVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VALUE,
            JitValueBox::normalizeValuePtr($context, $nullPtr)
        );
        foreach ([VmDom::PROP_PARENT_NODE, VmDom::PROP_NEXT_SIBLING, VmDom::PROP_PREVIOUS_SIBLING] as $prop) {
            $objectType->propertyStore(
                $objectType->propertySlotFor($node, 'DOMNode', $prop),
                $nullVar,
                JITVariable::TYPE_VALUE
            );
        }
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMNode::replaceChild() expects object nodes');
    }

    private static function boxObjectResult(Context $context, Value $object): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $object
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
