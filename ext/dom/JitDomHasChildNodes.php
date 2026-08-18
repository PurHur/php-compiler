<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMNode::hasChildNodes() (php-src xmlNode->children).
 *
 * Thin standalone AOT firstChild/documentElement temps lose DOMElement userType
 * and NestedJIT DomRegistry is empty — instance-invoke aborts. Probe the live
 * DOMElement firstChild slot (same layout as {@see JitDomAppendChildLiveSlots};
 * DOMNode firstChild aliases tagName — #32361).
 *
 * php-src: ext/dom/node.c PHP_METHOD(DOMNode, hasChildNodes) (#32427)
 */
final class JitDomHasChildNodes
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_haschildnodes_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMNode::hasChildNodes',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $receiver = $args[0] ?? null;
        if (null === $receiver) {
            throw new \LogicException('DOMNode::hasChildNodes() expects a receiver');
        }

        return self::boxBoolResult($context, self::firstChildSlotIsSet($context, $receiver));
    }

    /** php-src: RETURN_BOOL(nodep->children != NULL). */
    private static function firstChildSlotIsSet(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_FIRST_CHILD)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_FIRST_CHILD, JITVariable::TYPE_VALUE);
        }

        $firstVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            'DOMElement',
            VmDom::PROP_FIRST_CHILD,
            $elementClassId
        );
        $firstRaw = JitValueBox::valuePtrFromVariable($context, $firstVar);
        $slotNull = JitNestedHelperCoerce::isHelperResultNull($context, $firstRaw);

        $fn = $context->builder->getInsertBlock()->getParent();
        $yes = $fn->appendBasicBlock('dom_hcn_yes');
        $no = $fn->appendBasicBlock('dom_hcn_no');
        $done = $fn->appendBasicBlock('dom_hcn_done');
        $afterSlot = $fn->appendBasicBlock('dom_hcn_obj');
        $context->builder->branchIf($slotNull, $no, $afterSlot);

        $context->builder->positionAtEnd($afterSlot);
        $firstObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::normalizeValuePtr($context, $firstRaw)
        );
        $objPtr = $context->getTypeFromString('__object__*');
        $objNull = $context->builder->icmp(Builder::INT_EQ, $firstObj, $objPtr->constNull());
        $context->builder->branchIf($objNull, $no, $yes);

        $context->builder->positionAtEnd($yes);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($no);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $i1 = $context->getTypeFromString('int1');
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($i1->constInt(1, false), $yes);
        $phi->addIncoming($i1->constInt(0, false), $no);

        return $phi;
    }

    private static function loadObject(Context $context, JITVariable $arg): Value
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

        throw new \LogicException('DOMNode::hasChildNodes() receiver must be object or value box');
    }

    private static function boxBoolResult(Context $context, Value $flag): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $flag);

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
