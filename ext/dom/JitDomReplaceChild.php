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

/** LLVM lowering for DOMNode::replaceChild() (#19240, #22678). */
final class JitDomReplaceChild
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('DOMNode::replaceChild() expects receiver, newChild, oldChild');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replace_child_cont');
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
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replace_child_post');
        }

        return self::boxObjectResult($context, $oldChild);
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
