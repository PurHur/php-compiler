<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Builtin\DomCreateElementRuntime;
use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMDocument::createElement() (#17391).
 *
 * php-src: ext/dom/document.c — dom_document_create_element
 */
final class JitDomCreateElement
{
    private const CLASS_ELEMENT = 'DOMElement';

    private const PROP_NODE_NAME = 'nodeName';

    private const PROP_TAG_NAME = 'tagName';

    private const PROP_ATTRIBUTES = 'attributes';

    private const PROP_TEXT_CONTENT = 'textContent';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::createElement() expects receiver and name');
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $nameLit = self::compileTimeStringArg($args[1]);
            // Invalid literal must not silently materialize (#24804 / #20594 AOT gap).
            if (null !== $nameLit && !self::isValidXmlNameLit($nameLit)) {
                return self::invokeViaHelper($context, ...$args);
            }
            if (null !== $nameLit) {
                $obj = self::materializeElementFromLiteral($context, $nameLit);
                self::initTextContentSlot($context, $obj, $args[2] ?? null);
                self::storeOwnerAndNullParent($context, $obj, $args[0]);

                return $obj;
            }
            // Runtime name — helper applies xmlValidateName + strictErrorChecking.
            return self::invokeViaHelper($context, ...$args);
        }

        $nameLit = self::compileTimeStringArg($args[1]);
        if (null !== $nameLit) {
            return self::materializeElementFromLiteral($context, $nameLit);
        }

        return self::materializeElementFromRuntimeName($context, $args[1]);
    }

    /** Mirror VmDom::isValidXmlName for compile-time literal gating (#24804). */
    private static function isValidXmlNameLit(string $name): bool
    {
        return '' !== $name && 1 === preg_match('/^[A-Za-z_:][\w.:-]*$/', $name);
    }

    private static function invokeViaHelper(Context $context, JITVariable ...$args): Value
    {
        DomCreateElementRuntime::ensureLinked($context);

        $document = self::loadObjectArg($context, $args[0]);
        $name = self::loadStringArg($context, $args[1]);
        $valueArg = $args[2] ?? null;
        $value = null !== $valueArg
            ? self::loadStringArg($context, $valueArg)
            : $context->builder->load($context->constantStringFromString(''));
        $element = $context->builder->call(
            $context->lookupFunction(DomCreateElementRuntime::ABI_NAME),
            $document,
            $name,
            $value
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $element
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }

    private static function initTextContentSlot(Context $context, Value $element, ?JITVariable $valueArg): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_ELEMENT);
        if (!$objectType->hasProperty($classId, self::PROP_TEXT_CONTENT)) {
            $objectType->defineProperty($classId, self::PROP_TEXT_CONTENT, JITVariable::TYPE_STRING);
        }
        $lit = '';
        if (null !== $valueArg) {
            $lit = $valueArg->compileTimeString ?? JitStringBuiltinArg::compileTimeLiteral($valueArg) ?? '';
        }
        $textStr = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $textStr
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
        $objectType->propertyStore(
            $objectType->propertySlotFor($element, self::CLASS_ELEMENT, self::PROP_TEXT_CONTENT),
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

        throw new \LogicException('DOMDocument::createElement() receiver must be an object');
    }

    private static function compileTimeStringArg(JITVariable $arg): ?string
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }

        return $arg->compileTimeString;
    }

    public static function materializeElementFromLiteral(Context $context, string $nameLit): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_ELEMENT);
        self::ensureElementPropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        $nameStr = $context->builder->load($context->constantStringFromString($nameLit));
        self::storeStringProperty($context, $obj, self::CLASS_ELEMENT, self::PROP_NODE_NAME, $nameStr);
        self::storeStringProperty($context, $obj, self::CLASS_ELEMENT, self::PROP_TAG_NAME, $nameStr);
        self::storeNullProperty($context, $obj, self::CLASS_ELEMENT, self::PROP_ATTRIBUTES);

        return $obj;
    }

    /** User-script AOT: materialize DOMElement with textContent (#18493). */
    public static function materializeElementWithTextContent(
        Context $context,
        string $tag,
        string $textContent
    ): Value {
        $obj = self::materializeElementFromLiteral($context, $tag);
        if ('' === $textContent) {
            return $obj;
        }
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_ELEMENT);
        if (!$objectType->hasProperty($classId, 'textContent')) {
            $objectType->defineProperty($classId, 'textContent', JITVariable::TYPE_STRING);
        }
        $textStr = $context->builder->load($context->constantStringFromString($textContent));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $textStr
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_ELEMENT, 'textContent'),
            $propVar,
            JITVariable::TYPE_STRING
        );

        return $obj;
    }

    private static function materializeElementFromRuntimeName(Context $context, JITVariable $nameArg): Value
    {
        $nameStr = self::loadStringArg($context, $nameArg);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_ELEMENT);
        self::ensureElementPropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        self::storeStringProperty($context, $obj, self::CLASS_ELEMENT, self::PROP_NODE_NAME, $nameStr);
        self::storeStringProperty($context, $obj, self::CLASS_ELEMENT, self::PROP_TAG_NAME, $nameStr);
        self::storeNullProperty($context, $obj, self::CLASS_ELEMENT, self::PROP_ATTRIBUTES);

        return $obj;
    }

    private static function ensureElementPropertyLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $classId
    ): void {
        foreach ([self::PROP_NODE_NAME, self::PROP_TAG_NAME, self::PROP_ATTRIBUTES] as $prop) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $type = self::PROP_ATTRIBUTES === $prop ? JITVariable::TYPE_VALUE : JITVariable::TYPE_STRING;
                $objectType->defineProperty($classId, $prop, $type);
            }
        }
        // #21687: parentNode / ownerDocument for contains()/getRootNode without DomRegistry.
        if (!$objectType->hasProperty($classId, VmDom::PROP_PARENT_NODE)) {
            $objectType->defineProperty($classId, VmDom::PROP_PARENT_NODE, JITVariable::TYPE_VALUE);
        }
        if (!$objectType->hasProperty($classId, VmDom::PROP_OWNER_DOCUMENT)) {
            $objectType->defineProperty($classId, VmDom::PROP_OWNER_DOCUMENT, JITVariable::TYPE_VALUE);
        }
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        $valPtr = JitValueBox::valuePtrFromVariable($context, $arg);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valPtr
        );
    }

    private static function storeStringProperty(
        Context $context,
        Value $obj,
        string $className,
        string $prop,
        Value $str
    ): void {
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
            $context->type->object->propertySlotFor($obj, $className, $prop),
            $propVar,
            JITVariable::TYPE_STRING
        );
    }

    private static function storeNullProperty(Context $context, Value $obj, string $className, string $prop): void
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, $className, $prop),
            $propVar,
            JITVariable::TYPE_NULL
        );
    }

    /**
     * #21687: null parentNode + ownerDocument so living-API walks do not read garbage slots.
     */
    private static function storeOwnerAndNullParent(Context $context, Value $obj, JITVariable $documentArg): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_ELEMENT);
        self::ensureElementPropertyLayout($objectType, $classId);

        $nullSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $nullSlot)
        );
        $nullVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $nullSlot);
        $docObj = self::loadObjectArg($context, $documentArg);
        $docJit = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $docObj);
        // DOMElement layout only — writing DOMNode slots into a DOMElement allocation corrupts memory.
        if (!$objectType->hasProperty($classId, VmDom::PROP_OWNER_DOCUMENT)) {
            $objectType->defineProperty($classId, VmDom::PROP_OWNER_DOCUMENT, JITVariable::TYPE_VALUE);
        }
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_ELEMENT, VmDom::PROP_OWNER_DOCUMENT),
            $docJit,
            JITVariable::TYPE_VALUE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_ELEMENT, VmDom::PROP_PARENT_NODE),
            $nullVar,
            JITVariable::TYPE_VALUE
        );
    }
}
