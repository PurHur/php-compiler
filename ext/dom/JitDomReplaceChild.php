<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Builtin\DomNodeTreeMutationRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::replaceChild() (#19240, #22678, #27216).
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
            self::syncUserScriptReplaceSlots($context, $args[0], $args[1]);
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
     * Update firstChild/lastChild/parentNode LLVM slots for thin-AOT replaceChild (#27216).
     *
     * Skipping DomRegistry NestedJIT + clearDetachedLinkSlots: both segfault on
     * LLVM-materialized nodes (unregistered ids / null slot stores). ParentNode::append
     * already syncs live child lists the same way.
     */
    private static function syncUserScriptReplaceSlots(
        Context $context,
        JITVariable $parentVar,
        JITVariable $newChildVar
    ): void {
        $parent = self::loadObjectArg($context, $parentVar);
        $newChild = self::loadObjectArg($context, $newChildVar);
        $objectType = $context->type->object;

        $nodeClassId = $objectType->lookup('DOMNode');
        foreach ([VmDom::PROP_FIRST_CHILD, VmDom::PROP_LAST_CHILD] as $prop) {
            if (!$objectType->hasProperty($nodeClassId, $prop)) {
                $objectType->defineProperty($nodeClassId, $prop, JITVariable::TYPE_VALUE);
            }
        }
        $newJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $newChild);
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, 'DOMNode', VmDom::PROP_FIRST_CHILD),
            $newJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($parent, 'DOMNode', VmDom::PROP_LAST_CHILD),
            $newJit,
            JITVariable::TYPE_VALUE
        );

        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
        $parentJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parent);
        $objectType->propertyStore(
            $objectType->propertySlotFor($newChild, 'DOMElement', VmDom::PROP_PARENT_NODE),
            $parentJit,
            JITVariable::TYPE_VALUE
        );

        // Element nav slots for only-child replace (first/last element child → newChild).
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

        $tag = $newChildVar->compileTimeDomTagName ?? null;
        if (null !== $tag && '' !== $tag) {
            JitDomCreateElement::storeUserScriptInnerXml($context, $parent, '<'.$tag.'/>');
        }
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
