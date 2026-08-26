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
 * User-script AOT for DOMNode::hasAttributes() (php-src xmlNode->properties).
 *
 * Thin standalone AOT documentElement/firstChild temps lose DOMElement userType
 * and NestedJIT DomRegistry is empty — instance-invoke aborts. Probe the live
 * DOMElement attributes NamedNodeMap length (peer {@see JitDomHasChildNodes}).
 *
 * Empty length-0 maps are always allocated after #33128 so
 * {@code $el->attributes->length} matches Zend — slot non-null alone is not
 * {@code properties != NULL} (#34854 / re-#32458).
 *
 * php-src: ext/dom/node.c PHP_METHOD(DOMNode, hasAttributes) (#32458)
 */
final class JitDomHasAttributes
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_hasattributes_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMNode::hasAttributes',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        $receiver = $args[0] ?? null;
        if (null === $receiver) {
            throw new \LogicException('DOMNode::hasAttributes() expects a receiver');
        }

        return self::boxBoolResult($context, self::attributesMapLengthNonZero($context, $receiver));
    }

    /**
     * php-src: RETURN_BOOL(nodep->properties != NULL).
     *
     * Live map length > 0 — empty NamedNodeMap after #33128 must return false (#34854).
     */
    private static function attributesMapLengthNonZero(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $elementClassId = $objectType->lookup('DOMElement');
        if (!$objectType->hasProperty($elementClassId, VmDom::PROP_ATTRIBUTES)) {
            $objectType->defineProperty($elementClassId, VmDom::PROP_ATTRIBUTES, JITVariable::TYPE_VALUE);
        }

        $attrsVar = ObjectInstancePropertyLlvm::propertyFetchDeclaredSlot(
            $objectType,
            $obj,
            'DOMElement',
            VmDom::PROP_ATTRIBUTES,
            $elementClassId
        );
        $attrsRaw = JitValueBox::valuePtrFromVariable($context, $attrsVar);
        $slotNull = JitNestedHelperCoerce::isHelperResultNull($context, $attrsRaw);

        $fn = $context->builder->getInsertBlock()->getParent();
        $yes = $fn->appendBasicBlock('dom_hasattr_yes');
        $no = $fn->appendBasicBlock('dom_hasattr_no');
        $done = $fn->appendBasicBlock('dom_hasattr_done');
        $afterSlot = $fn->appendBasicBlock('dom_hasattr_obj');
        $afterMap = $fn->appendBasicBlock('dom_hasattr_len');
        $context->builder->branchIf($slotNull, $no, $afterSlot);

        $context->builder->positionAtEnd($afterSlot);
        $attrsObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::normalizeValuePtr($context, $attrsRaw)
        );
        $objPtr = $context->getTypeFromString('__object__*');
        $objNull = $context->builder->icmp(Builder::INT_EQ, $attrsObj, $objPtr->constNull());
        $context->builder->branchIf($objNull, $no, $afterMap);

        $context->builder->positionAtEnd($afterMap);
        $lengthVar = JitDomNamedNodeMap::fetchLength($objectType, $attrsObj);
        $length = $context->helper->loadValue($lengthVar);
        $i64 = $context->getTypeFromString('int64');
        $nonzero = $context->builder->icmp(
            Builder::INT_NE,
            $length,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($nonzero, $yes, $no);

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

        throw new \LogicException('DOMNode::hasAttributes() receiver must be object or value box');
    }

    private static function boxBoolResult(Context $context, Value $flag): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $flag);

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
