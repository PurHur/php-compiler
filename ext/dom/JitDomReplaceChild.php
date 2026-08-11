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

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            self::syncUserScriptReplaceSlots($context, $args[0], $args[1], $args[2]);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replace_child_post');

            return self::boxObjectResult($context, self::loadObjectArg($context, $args[2]));
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
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
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

        self::syncUserScriptInnerXml($context, $parent, $newChildVar, $oldChildVar, $xml);
    }

    /**
     * Rebuild PROP_USER_SCRIPT_INNER_XML so saveXML keeps non-replaced siblings (#28671).
     *
     * Prior path always stored {@code <newTag/>}, dropping remaining children.
     */
    private static function syncUserScriptInnerXml(
        Context $context,
        Value $parent,
        JITVariable $newChildVar,
        JITVariable $oldChildVar,
        ?string $xml
    ): void {
        $newTag = $newChildVar->compileTimeDomTagName ?? null;
        if (null === $newTag || '' === $newTag) {
            return;
        }
        $replacement = '<'.$newTag.'/>';

        if (null !== $xml && JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
            $index = $oldChildVar->compileTimeDomChildIndex ?? null;
            if (null === $index) {
                $oldTag = $oldChildVar->compileTimeDomTagName ?? null;
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

                    return;
                }
            }
            // Multi-child without proven index: leave seeded INNER_XML (do not collapse).

            return;
        }

        // createElement-only trees / only-child: single-tag inner (legacy #27216).
        JitDomCreateElement::storeUserScriptInnerXml($context, $parent, $replacement);
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
