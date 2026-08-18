<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script AOT materialization for DOMDocument::createDocumentFragment() (#20203).
 *
 * Uses a DOMElement stand-in (same pattern as {@see JitDomCreateComment}) because
 * allocating an unregistered DOMDocumentFragment class aborts LLVM codegen in standalone AOT.
 * Stores ownerDocument = creating document so `$frag->ownerDocument === $doc` matches php-src.
 * Seeds empty textContent / INNER_XML so saveXML slot fetches are defined (#32334).
 *
 * php-src: ext/dom/document.c — dom_document_create_document_fragment
 */
final class JitDomCreateDocumentFragment
{
    private const CLASS_STANDIN = 'DOMElement';

    private const PROP_NODE_NAME = 'nodeName';

    private const PROP_TEXT_CONTENT = 'textContent';

    private const PROP_OWNER_DOCUMENT = 'ownerDocument';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_cdf_materialize_cont');
        if ([] === $args) {
            throw new \LogicException('DOMDocument::createDocumentFragment() called without $this');
        }

        $document = self::loadObjectArg($context, $args[0]);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_STANDIN);
        self::ensurePropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        self::storeStringLiteral($context, $obj, self::PROP_NODE_NAME, '#document-fragment');
        // saveXML fetches textContent/INNER_XML on every node (#32315). Empty fragment
        // xmlNodeDump is "" (php-src ext/dom/document.c → xmlNewDocFragment).
        self::storeStringLiteral($context, $obj, self::PROP_TEXT_CONTENT, '');
        self::storeStringLiteral($context, $obj, VmDom::PROP_USER_SCRIPT_INNER_XML, '');
        // DOMNode::$ownerDocument is a TYPE_VALUE slot (nullable object); match declared layout.
        $ownerVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $document);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_STANDIN, self::PROP_OWNER_DOCUMENT),
            $ownerVar,
            JITVariable::TYPE_VALUE
        );

        return $obj;
    }

    private static function ensurePropertyLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $classId
    ): void {
        if (!$objectType->hasProperty($classId, self::PROP_NODE_NAME)) {
            $objectType->defineProperty($classId, self::PROP_NODE_NAME, JITVariable::TYPE_STRING);
        }
        if (!$objectType->hasProperty($classId, self::PROP_TEXT_CONTENT)) {
            $objectType->defineProperty($classId, self::PROP_TEXT_CONTENT, JITVariable::TYPE_STRING);
        }
        if (!$objectType->hasProperty($classId, VmDom::PROP_USER_SCRIPT_INNER_XML)) {
            $objectType->defineProperty($classId, VmDom::PROP_USER_SCRIPT_INNER_XML, JITVariable::TYPE_STRING);
        }
        if (!$objectType->hasProperty($classId, self::PROP_OWNER_DOCUMENT)) {
            $objectType->defineProperty($classId, self::PROP_OWNER_DOCUMENT, JITVariable::TYPE_VALUE);
        }
    }

    private static function storeStringLiteral(Context $context, Value $obj, string $prop, string $lit): void
    {
        $str = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $propVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $owned
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, self::CLASS_STANDIN, $prop),
            $propVar,
            JITVariable::TYPE_STRING
        );
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

        throw new \LogicException('DOMDocument::createDocumentFragment() receiver must be an object');
    }
}
