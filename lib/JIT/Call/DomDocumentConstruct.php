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
 * libxml option bool slots (#34908 leftover of #34899).
 *
 * php-src: ext/dom/document.c / node.c — XML_DOCUMENT_NODE; php_dom.c construct
 * defaults for formatOutput / preserveWhiteSpace / ….
 * Must be listed in JIT::isVoidJitConstructCall so markObjectConstructed runs.
 */
final class DomDocumentConstruct implements Call
{
    /** Zend construct defaults (php-src ext/dom/document.c; VmDom::initDocumentLibxmlDefaults). */
    private const OPTION_DEFAULTS = [
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

        // Seed allocated option slots so PropertyFetch uses ClassProperty values and
        // PropertyAssign sticks (#34908 — MetaProps must not hardcode these).
        self::seedOptionDefaults($context, $obj, $classId);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private static function seedOptionDefaults(Context $context, Value $obj, int $classId): void
    {
        $objectType = $context->type->object;
        $i1 = $context->getTypeFromString('int1');
        foreach (self::OPTION_DEFAULTS as $prop => $default) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $objectType->defineProperty($classId, $prop, Variable::TYPE_NATIVE_BOOL);
            }
            $flag = new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $i1->constInt($default ? 1 : 0, false)
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, 'DOMDocument', $prop),
                $flag,
                Variable::TYPE_NATIVE_BOOL
            );
        }
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
