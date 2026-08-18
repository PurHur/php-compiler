<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Compile-time text-child stand-in for user-script AOT (#18951, #27260, #32315).
 *
 * Uses a DOMElement stand-in (peer {@see JitDomCreateComment}) because allocating an
 * unregistered DOMText class aborts LLVM codegen in standalone AOT. Slot layout exposes
 * nodeName / nodeValue / textContent / data for property reads after loadXML whitespace.
 *
 * php-src: ext/dom/document.c PHP_METHOD(DOMDocument, createTextNode)
 */
final class JitDomCreateTextNode
{
    /** Compile-time data of the last materialized text stand-in (#32362). */
    public static ?string $lastMaterializedData = null;

    private const CLASS_STANDIN = 'DOMElement';

    private const PROP_NODE_NAME = 'nodeName';

    private const PROP_NODE_VALUE = 'nodeValue';

    private const PROP_TEXT_CONTENT = 'textContent';

    private const PROP_DATA = 'data';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::createTextNode() expects receiver and data');
        }

        if ($context->callerStrictTypes && JITVariable::TYPE_NULL === $args[1]->type) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMDocument::createTextNode(): Argument #1 ($data) must be of type string, null given'
            );

            return self::boxNullResult($context);
        }

        $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null !== $lit) {
            return self::materialize($context, $lit);
        }

        return self::materializeFromRuntimeData($context, $args[1]);
    }

    public static function materialize(Context $context, string $data = ''): Value
    {
        self::$lastMaterializedData = $data;
        JitDomSubstringData::remember($data);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_STANDIN);
        self::ensurePropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        self::storeStringLiteral($context, $obj, self::PROP_NODE_NAME, '#text');
        self::storeStringLiteral($context, $obj, self::PROP_NODE_VALUE, $data);
        self::storeStringLiteral($context, $obj, self::PROP_TEXT_CONTENT, $data);
        self::storeStringLiteral($context, $obj, self::PROP_DATA, $data);

        return $obj;
    }

    /** Rewrite data/nodeValue/textContent on an existing text stand-in (#32362). */
    public static function overwriteCharacterData(Context $context, Value $obj, string $data): void
    {
        self::$lastMaterializedData = $data;
        JitDomSubstringData::remember($data);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_STANDIN);
        self::ensurePropertyLayout($objectType, $classId);
        self::storeStringLiteral($context, $obj, self::PROP_NODE_VALUE, $data);
        self::storeStringLiteral($context, $obj, self::PROP_TEXT_CONTENT, $data);
        self::storeStringLiteral($context, $obj, self::PROP_DATA, $data);
    }

    private static function materializeFromRuntimeData(Context $context, JITVariable $dataArg): Value
    {
        self::$lastMaterializedData = null;
        JitDomSubstringData::remember(null);
        $dataStr = self::loadStringArg($context, $dataArg);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_STANDIN);
        self::ensurePropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        self::storeStringLiteral($context, $obj, self::PROP_NODE_NAME, '#text');
        self::storeStringValue($context, $obj, self::PROP_NODE_VALUE, $dataStr);
        self::storeStringValue($context, $obj, self::PROP_TEXT_CONTENT, $dataStr);
        self::storeStringValue($context, $obj, self::PROP_DATA, $dataStr);

        return $obj;
    }

    private static function ensurePropertyLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $classId
    ): void {
        foreach ([
            self::PROP_NODE_NAME,
            self::PROP_NODE_VALUE,
            self::PROP_TEXT_CONTENT,
            self::PROP_DATA,
        ] as $prop) {
            if (!$objectType->hasProperty($classId, $prop)) {
                $objectType->defineProperty($classId, $prop, JITVariable::TYPE_STRING);
            }
        }
    }

    private static function storeStringLiteral(Context $context, Value $obj, string $prop, string $lit): void
    {
        $str = $context->builder->load($context->constantStringFromString($lit));
        self::storeStringValue($context, $obj, $prop, $str);
    }

    private static function storeStringValue(Context $context, Value $obj, string $prop, Value $str): void
    {
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

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
    }

    private static function boxNullResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $ptr
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
