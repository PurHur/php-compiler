<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNodeTreeMutationRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::removeChild() (#19240, #27475, #32774).
 *
 * Thin standalone AOT: LiveSlots unlink + in-place childNodes length (#32774),
 * mirroring {@see JitDomReplaceChild} (skip NestedJIT DomRegistry on LLVM-materialized
 * nodes). Prior path called the ABI then {@see writeEmptyChildNodesList}, which
 * replaced the live list with a fresh length-0 object — held `$list` stayed stale
 * and refetch reported 0 while siblings remained (php-src ext/dom/node.c /
 * nodelist.c).
 */
final class JitDomRemoveChild
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMNode::removeChild() expects receiver and child node');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_remove_child_cont');
        if (JitDomRequireDomNodeArg::guardOrAbort($context, $args[1], 'DOMNode::removeChild', 1, 'child')) {
            return JitDomRequireDomNodeArg::boxNullResult($context);
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $parent = self::loadObjectArg($context, $args[0]);
            $child = self::loadObjectArg($context, $args[1]);
            JitDomRemoveChildLiveSlots::sync($context, $parent, $child);
            DomUserScriptElementCacheLlvm::invalidateIfElement($context, $child);
            self::syncUserScriptInnerXmlAfterRemove($context, $parent, $args[1]);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_remove_child_post');

            return self::boxObjectResult($context, $child);
        }

        DomNodeTreeMutationRuntime::ensureRemoveChildLinked($context);

        $parent = self::loadObjectArg($context, $args[0]);
        $child = self::loadObjectArg($context, $args[1]);
        $context->builder->call(
            $context->lookupFunction(DomNodeTreeMutationRuntime::ABI_REMOVE_CHILD),
            $parent,
            $child
        );
        self::clearDetachedLinkSlots($context, $child);

        return self::boxObjectResult($context, $child);
    }

    /**
     * Drop the removed child's tag from PROP_USER_SCRIPT_INNER_XML when the
     * loadXML seed is still pure user-script (saveXML sibling fidelity).
     *
     * item($N) ARG_SEND temps often drop compileTimeDomChildIndex — mirror
     * {@see JitDomReplaceChild} lastFetched* fallbacks (#32903 / #32942).
     */
    private static function syncUserScriptInnerXmlAfterRemove(
        Context $context,
        Value $parent,
        JITVariable $childVar
    ): void {
        $xml = JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || !JitDomLoadXMLUserScript::lastLoadWasPureUserScript()) {
            return;
        }
        $nodes = DomParseSimpleXmlJitHelper::directChildNodesArgv($xml);
        $index = $childVar->compileTimeDomChildIndex
            ?? JitDomNodeListItem::$lastFetchedChildIndex
            ?? JitDomNodeChildProperty::$lastFetchedChildIndex
            ?? null;
        if (null === $index) {
            $oldTag = $childVar->compileTimeDomTagName
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
        if (null === $index) {
            // Multi-child without proven index: leave seeded INNER_XML (do not collapse).
            return;
        }
        $inner = DomParseSimpleXmlJitHelper::rootInnerXmlReplaceChildAt($xml, $index, '');
        if (null !== $inner) {
            JitDomCreateElement::storeUserScriptInnerXml($context, $parent, $inner);
            JitDomLoadXMLUserScript::refreshCompileTimeXmlWithRootInner($inner);
        }
    }

    /** Null parent/sibling LLVM slots on the detached node (ext/dom/node.c; #19240). */
    private static function clearDetachedLinkSlots(Context $context, Value $node): void
    {
        $objectType = $context->type->object;
        $nodeClassId = $objectType->lookup('DOMNode');
        $elementClassId = $objectType->lookup('DOMElement');
        foreach ([VmDom::PROP_PARENT_NODE, VmDom::PROP_NEXT_SIBLING, VmDom::PROP_PREVIOUS_SIBLING] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
            // createElement / loadXML / LiveSlots store parent+siblings on DOMElement
            // (#27476 / #28672 / #29434) — nulling only DOMNode leaves isConnected true.
            if (!$objectType->hasProperty($elementClassId, $prop)) {
                $objectType->defineProperty($elementClassId, $prop, JITVariable::TYPE_VALUE);
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
            $objectType->propertyStore(
                $objectType->propertySlotFor($node, 'DOMElement', $prop),
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

        throw new \LogicException('DOMNode::removeChild() expects object nodes');
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
