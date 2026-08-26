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
 *
 * Also seeds libxml option bools (#34908) and xmlVersion/xmlStandalone (#34916)
 * so PropertyAssign sticks (MetaProps no longer hardcodes those fetches).
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

        // Seed libxml option bools so reads work and writes stick (#34908).
        // php-src DOMDocument::__construct defaults — ext/dom/php_dom.c / document.c.
        self::seedOptionBool($context, $obj, VmDom::PROP_FORMAT_OUTPUT, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_VALIDATE_ON_PARSE, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_RESOLVE_EXTERNALS, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_SUBSTITUTE_ENTITIES, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_PRESERVE_WHITE_SPACE, true);
        self::seedOptionBool($context, $obj, VmDom::PROP_RECOVER, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_STRICT_ERROR_CHECKING, true);

        // xmlVersion / xmlStandalone (+ legacy aliases) — same seed pattern (#34916).
        // php-src: ext/dom/document.c — version/xmlVersion, standalone/xmlStandalone.
        self::seedStringProp($context, $obj, VmDom::PROP_XML_VERSION, '1.0');
        self::seedStringProp($context, $obj, VmDom::PROP_VERSION, '1.0');
        self::seedOptionBool($context, $obj, VmDom::PROP_XML_STANDALONE, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_STANDALONE, false);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private static function seedOptionBool(Context $context, Value $obj, string $prop, bool $value): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, $prop)) {
            $objectType->defineProperty($classId, $prop, Variable::TYPE_VALUE);
        }
        $box = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        JitValueBox::writeBool(
            $context,
            $box,
            $context->builder->zext($i1->constInt($value ? 1 : 0, false), $i32)
        );
        $propVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $box
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', $prop),
            $propVar,
            Variable::TYPE_VALUE
        );
    }

    private static function seedStringProp(Context $context, Value $obj, string $prop, string $value): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, $prop)) {
            $objectType->defineProperty($classId, $prop, Variable::TYPE_STRING);
        }
        $str = $context->builder->load($context->constantStringFromString($value));
        $propVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $str
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', $prop),
            $propVar,
            Variable::TYPE_STRING
        );
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
