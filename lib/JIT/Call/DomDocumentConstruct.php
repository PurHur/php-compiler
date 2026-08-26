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

        // Seed libxml option bools so reads work and writes stick (#34908).
        // php-src DOMDocument::__construct defaults — ext/dom/php_dom.c / document.c.
        self::seedOptionBool($context, $obj, VmDom::PROP_FORMAT_OUTPUT, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_VALIDATE_ON_PARSE, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_RESOLVE_EXTERNALS, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_SUBSTITUTE_ENTITIES, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_PRESERVE_WHITE_SPACE, true);
        self::seedOptionBool($context, $obj, VmDom::PROP_RECOVER, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_STRICT_ERROR_CHECKING, true);
        // xmlVersion / xmlStandalone (+ Level-3 aliases) — same MetaProps leftover (#34916).
        self::seedOptionBool($context, $obj, VmDom::PROP_XML_STANDALONE, false);
        self::seedOptionBool($context, $obj, VmDom::PROP_STANDALONE, false);
        self::seedXmlVersion($context, $obj, '1.0');
        // encoding null — writable slot; xmlEncoding/actualEncoding alias via MetaProps (#34919).
        self::seedEncodingNull($context, $obj);
        // documentURI null — writable; baseURI read-only alias via MetaProps (#34925).
        self::seedDocumentUriNull($context, $obj);
        // DOMNode identity on Document (php-src ext/dom/node.c; #34992 leftover of #34899).
        self::seedNodeName($context, $obj, '#document');
        self::seedPrefixEmpty($context, $obj);
        self::seedNullValueProp($context, $obj, VmDom::PROP_NAMESPACE_URI);
        self::seedNullValueProp($context, $obj, VmDom::PROP_LOCAL_NAME);
        self::seedNullValueProp($context, $obj, VmDom::PROP_ATTRIBUTES);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }

    private static function seedXmlVersion(Context $context, Value $obj, string $version): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        $str = $context->builder->load($context->constantStringFromString($version));
        foreach ([VmDom::PROP_XML_VERSION, VmDom::PROP_VERSION] as $prop) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $objectType->defineProperty($classId, $prop, Variable::TYPE_STRING);
            }
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $str
            );
            $propVar = new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $owned
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, 'DOMDocument', $prop),
                $propVar,
                Variable::TYPE_STRING
            );
        }
    }

    /** php-src DOMDocument::$encoding default null (ext/dom/php_dom.c; #34919). */
    private static function seedEncodingNull(Context $context, Value $obj): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, VmDom::PROP_ENCODING)) {
            $objectType->defineProperty($classId, VmDom::PROP_ENCODING, Variable::TYPE_VALUE);
        }
        $box = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $box)
        );
        $propVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $box
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', VmDom::PROP_ENCODING),
            $propVar,
            Variable::TYPE_VALUE
        );
    }

    /** php-src DOMDocument::$documentURI default null (ext/dom/document.c; #34925). */
    private static function seedDocumentUriNull(Context $context, Value $obj): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, VmDom::PROP_DOCUMENT_URI)) {
            $objectType->defineProperty($classId, VmDom::PROP_DOCUMENT_URI, Variable::TYPE_VALUE);
        }
        $box = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $box)
        );
        $propVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $box
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', VmDom::PROP_DOCUMENT_URI),
            $propVar,
            Variable::TYPE_VALUE
        );
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

    /** php-src DOMNode::$nodeName for XML_DOCUMENT_NODE — "#document" (#34992). */
    private static function seedNodeName(Context $context, Value $obj, string $name): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, VmDom::PROP_NODE_NAME)) {
            $objectType->defineProperty($classId, VmDom::PROP_NODE_NAME, Variable::TYPE_STRING);
        }
        $str = $context->builder->load($context->constantStringFromString($name));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $propVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $owned
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', VmDom::PROP_NODE_NAME),
            $propVar,
            Variable::TYPE_STRING
        );
    }

    /** php-src DOMNode::$prefix for documents — empty string (#34992). */
    private static function seedPrefixEmpty(Context $context, Value $obj): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, VmDom::PROP_PREFIX)) {
            $objectType->defineProperty($classId, VmDom::PROP_PREFIX, Variable::TYPE_STRING);
        }
        $str = $context->builder->load($context->constantStringFromString(''));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $propVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $owned
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DOMDocument', VmDom::PROP_PREFIX),
            $propVar,
            Variable::TYPE_STRING
        );
    }

    /**
     * Seed a nullable DOMNode VALUE prop to null (namespaceURI / localName / attributes
     * on Document — #34992).
     */
    private static function seedNullValueProp(Context $context, Value $obj, string $prop): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DOMDocument');
        if (!$objectType->hasProperty($classId, $prop)) {
            $objectType->defineProperty($classId, $prop, Variable::TYPE_VALUE);
        }
        $box = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $box)
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
