<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

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

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::createElement() expects receiver and name');
        }

        $nameLit = self::compileTimeStringArg($args[1]);
        if (null !== $nameLit) {
            return self::materializeElementFromLiteral($context, $nameLit);
        }

        return self::materializeElementFromRuntimeName($context, $args[1]);
    }

    private static function compileTimeStringArg(JITVariable $arg): ?string
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return $lit;
        }

        return $arg->compileTimeString;
    }

    private static function materializeElementFromLiteral(Context $context, string $nameLit): Value
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
}
