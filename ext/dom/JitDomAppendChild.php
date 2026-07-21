<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for DOMNode::appendChild() (#18478). */
final class JitDomAppendChild
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMNode::appendChild() expects receiver and child node');
        }

        if (!JitDomDocumentMethodKernel::shouldUse($context)) {
            throw new \LogicException('DOMNode::appendChild() user-script LLVM bridge required in this build');
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ac_call_cont');

        $tagGlobal = $context->module->getNamedGlobal(DomUserScriptLiveTagListLlvm::GLOBAL_TAG);
        if (null !== $tagGlobal) {
            $tagStr = $context->builder->load($tagGlobal);
            DomUserScriptLiveTagListLlvm::increment($context, $tagStr);
        }

        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_ac_post_inc');

        // #21687: mirror parentNode so contains()/getRootNode can walk after appendChild.
        if (JITVariable::TYPE_OBJECT === $args[0]->type && JITVariable::TYPE_OBJECT === $args[1]->type) {
            $parentObj = $context->helper->loadValue($args[0]);
            $childObj = $context->helper->loadValue($args[1]);
            $objectType = $context->type->object;
            $parentJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $parentObj);
            $elementClassId = $objectType->lookup('DOMElement');
            if (!$objectType->hasProperty($elementClassId, VmDom::PROP_PARENT_NODE)) {
                $objectType->defineProperty($elementClassId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
            }
            $objectType->propertyStore(
                $objectType->propertySlotFor($childObj, 'DOMElement', VmDom::PROP_PARENT_NODE),
                $parentJit,
                JITVariable::TYPE_VALUE
            );
        }

        if (JITVariable::TYPE_OBJECT === $args[1]->type) {
            return self::boxObjectResult($context, $context->helper->loadValue($args[1]));
        }

        return JitValueBox::valuePtrFromVariable($context, $args[1]);
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
