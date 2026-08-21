<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomNodeLiveMutationRuntime;
use PHPCompiler\JIT\Builtin\DomNodeTreeMutationRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::replaceChild() (#19240, #22678, #27216, #28671).
 *
 * Thin standalone AOT materializes createElement nodes without DomRegistry
 * ({@see JitDomCreateElement::materializeElementFromLiteral}). The NestedJIT
 * DomRegistry bridge then sees unregistered objects and segfaults — mirror the
 * ParentNode::append LLVM slot sync instead (php-src ext/dom/node.c).
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
            self::syncUserScriptReplaceSlots($context, $args[0], $args[1], $args[2]);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replace_child_post');
            $context->builder->store(
                self::boxObjectResult($context, self::loadObjectArg($context, $args[2])),
                $resultSlot
            );
            $context->builder->branch($bbEnd);

            $context->builder->positionAtEnd($bbEnd);

            // Dual-emit runs invokeDocumentReplace *and* syncUserScriptReplaceSlots at
            // compile time. refreshCompileTimeXmlReplaceRoot rewrites every
            // compileTimeDomLoadXml binding to the replacement outer markup (#33379),
            // which poisons DOMElement::replaceChild saveXML to just <x/>. Force the
            // mutated flag after both arms so saveXML serializes from live slots.
            JitDomLoadXMLUserScript::markTreeMutatedSinceLoad();

            return $context->builder->load($resultSlot);
        }

        DomNodeTreeMutationRuntime::ensureReplaceChildLinked($context);

        $parent = self::loadObjectArg($context, $args[0]);
        $newChild = self::loadObjectArg($context, $args[1]);
        $oldChild = self::loadObjectArg($context, $args[2]);
        $context->builder->call(
            $context->lookupFunction(DomNodeTreeMutationRuntime::ABI_REPLACE_CHILD),
            $parent,
            $newChild,
            $oldChild
        );
        // Null AOT property slots on the replaced node (#19240). Identity no-op still hits this
        // path today (pointer compare unreliable); VmDom short-circuit keeps the live tree (#22678).
        self::clearDetachedLinkSlots($context, $oldChild);

        return self::boxObjectResult($context, $oldChild);
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

        DomNodeLiveMutationRuntime::assertTreeMutationChildBeforeLiveSlots(
            $context,
            self::loadObjectArg($context, $documentVar),
            self::loadObjectArg($context, $newChildVar)
        );

        $document = self::loadObjectArg($context, $documentVar);
        $newChild = self::loadObjectArg($context, $newChildVar);
        $oldChild = self::loadObjectArg($context, $oldChildVar);
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
        DomUserScriptElementCacheLlvm::invalidateIfElement($context, $oldChild);
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

        return self::boxObjectResult($context, $oldChild);
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

        // Wrong Document / Hierarchy Request before LiveSlots (#30274).
        DomNodeLiveMutationRuntime::assertTreeMutationChildBeforeLiveSlots(
            $context,
            $parent,
            $newChild
        );

        $childCount = null;
        $xml = $parentVar->compileTimeDomLoadXml
            ?? JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null !== $xml && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            $childCount = \count(DomParseSimpleXmlJitHelper::directChildNodesArgv($xml));
        }

        JitDomReplaceChildLiveSlots::sync($context, $parent, $newChild, $oldChild, $childCount);
        // Drop thin-AOT getElementById cache when the cached element is detached (#29694).
        DomUserScriptElementCacheLlvm::invalidateIfElement($context, $oldChild);

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
    }

    /**
     * Rebuild PROP_USER_SCRIPT_INNER_XML so saveXML keeps non-replaced siblings (#28671).
     *
     * Prior path always stored {@code <newTag/>}, dropping remaining children.
     */
    private static function syncUserScriptInnerXml(
        Context $context,
        JITVariable $parentVar,
        Value $parent,
        JITVariable $newChildVar,
        JITVariable $oldChildVar,
        ?string $xml
    ): void {
        $newTag = $newChildVar->compileTimeDomTagName ?? null;
        if (null === $newTag || '' === $newTag) {
            return;
        }
        // createElement($name, $value) stamps escaped text on compileTimeDomInnerXml (#32903).
        $newInner = $newChildVar->compileTimeDomInnerXml ?? '';
        $replacement = '' === $newInner
            ? '<'.$newTag.'/>'
            : '<'.$newTag.'>'.$newInner.'</'.$newTag.'>';

        $xml ??= $parentVar->compileTimeDomLoadXml;
        if (null !== $xml && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
            // item($N) ARG_SEND temps often drop compileTimeDomChildIndex (#32903).
            $index = $oldChildVar->compileTimeDomChildIndex
                ?? JitDomNodeListItem::$lastFetchedChildIndex
                ?? JitDomNodeChildProperty::$lastFetchedChildIndex
                ?? null;
            if (null === $index) {
                $oldTag = $oldChildVar->compileTimeDomTagName
                    ?? JitDomNodeListItem::$lastFetchedTagName
                    ?? JitDomNodeChildProperty::$lastFetchedTagName
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

        // createElement-only trees / only-child: single-tag inner (legacy #27216).
        JitDomCreateElement::storeUserScriptInnerXml($context, $parent, $replacement);
        JitDomLoadXMLUserScript::refreshCompileTimeXmlWithRootInner($replacement, $parentVar);
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
