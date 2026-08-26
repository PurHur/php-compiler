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
 * DOMDocument::__construct — seed thin-AOT DOMNode::$nodeType (#33607) and
 * libxml option bool slots (php-src ext/dom/document.c; #34908 leftover of #34899).
 *
 * Must be listed in JIT::isVoidJitConstructCall so markObjectConstructed runs.
 */
final class DomDocumentConstruct implements Call
{
    /** @var array<string, bool> Zend defaults — VmDom::initDocumentLibxmlDefaults / document.c */
    private const LIBXML_OPTION_DEFAULTS = [
        VmDom::PROP_STRICT_ERROR_CHECKING => true,
        VmDom::PROP_FORMAT_OUTPUT => false,
        VmDom::PROP_VALIDATE_ON_PARSE => false,
        VmDom::PROP_RESOLVE_EXTERNALS => false,
        VmDom::PROP_PRESERVE_WHITE_SPACE => true,
        VmDom::PROP_RECOVER => false,
        VmDom::PROP_SUBSTITUTE_ENTITIES => false,
    ];

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

        // Option bools are ClassProperty slots (Object_::allocate layout) — seed Zend
        // defaults so reads match without MetaProps hardcoding; assigns then stick (#34908).
        $i1 = $context->getTypeFromString('int1');
        foreach (self::LIBXML_OPTION_DEFAULTS as $prop => $default) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $objectType->defineProperty($classId, $prop, Variable::TYPE_NATIVE_BOOL);
            }
            $boolVar = new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $i1->constInt($default ? 1 : 0, false)
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, 'DOMDocument', $prop),
                $boolVar,
                Variable::TYPE_NATIVE_BOOL
            );
        }

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
