<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
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

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::createElement() expects receiver and name');
        }

        $nameStr = self::loadStringArg($context, $args[1]);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_ELEMENT);
        self::ensureElementPropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $objectType->initializePropertySlotsNull($obj, $classId);
        self::storeStringValueProperty($context, $obj, self::CLASS_ELEMENT, self::PROP_NODE_NAME, $nameStr);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }

    private static function ensureElementPropertyLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $classId
    ): void {
        if ($objectType->hasProperty($classId, self::PROP_NODE_NAME)) {
            return;
        }
        $objectType->defineProperty($classId, self::PROP_NODE_NAME, JITVariable::TYPE_VALUE);
        $objectType->defineProperty($classId, 'attributes', JITVariable::TYPE_VALUE);
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

    private static function storeStringValueProperty(
        Context $context,
        Value $obj,
        string $className,
        string $prop,
        Value $str
    ): void {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $str
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotPtr($obj, 0),
            $propVar,
            JITVariable::TYPE_VALUE
        );
    }
}
