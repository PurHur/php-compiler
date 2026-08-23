<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\DomConstants;
use PHPCompiler\ext\dom\JitDomCreateElement;
use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DOMDocument::__construct — seed thin-AOT DOMNode::$nodeType (#33607).
 *
 * php-src: ext/dom/document.c / node.c — XML_DOCUMENT_NODE.
 * Must be listed in JIT::isVoidJitConstructCall so markObjectConstructed runs.
 */
final class DomDocumentConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('DOMDocument::__construct() called without $this');
        }
        $obj = self::objectPtr($context, $args[0]);
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, VmDom::PROP_NODE_TYPE)) {
            $objectType->defineProperty($classId, VmDom::PROP_NODE_TYPE, Variable::TYPE_NATIVE_LONG);
        }
        JitDomCreateElement::storeNodeType(
            $context,
            $obj,
            'DOMDocument',
            DomConstants::XML_DOCUMENT_NODE
        );

        // Empty document: documentElement is null (php-src ext/dom/document.c; #32736).
        if (!$objectType->hasProperty($classId, VmDom::PROP_DOCUMENT_ELEMENT)) {
            $objectType->defineProperty($classId, VmDom::PROP_DOCUMENT_ELEMENT, Variable::TYPE_OBJECT);
        }
        $nullEl = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $context->getTypeFromString('__object__*')->constNull()
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', VmDom::PROP_DOCUMENT_ELEMENT),
            $nullEl,
            Variable::TYPE_OBJECT
        );

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private static function objectPtr(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException(
            'DOMDocument::__construct() expects an object, got '
            .Variable::getStringType($receiver->type)
        );
    }
}
