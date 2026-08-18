<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script AOT materialization for DOMEntityReference (#32343).
 *
 * Uses a DOMElement stand-in (peer {@see JitDomCreateComment}) because allocating an
 * unregistered DOMEntityReference class aborts LLVM codegen in standalone AOT.
 * {@see TAG_KIND} (`#entity-ref`) is the saveXML discriminator — Zend `nodeName` is the entity name.
 *
 * php-src: ext/dom/document.c PHP_METHOD(DOMDocument, createEntityReference) → xmlNewReference
 */
final class JitDomCreateEntityReference
{
    private const CLASS_STANDIN = 'DOMElement';

    /** Internal saveXML discriminator; not a Zend entity-ref nodeName. */
    public const TAG_KIND = '#entity-ref';

    private const PROP_NODE_NAME = 'nodeName';

    private const PROP_TAG_NAME = 'tagName';

    private const PROP_TEXT_CONTENT = 'textContent';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::createEntityReference() expects receiver and name');
        }

        if ($context->callerStrictTypes && JITVariable::TYPE_NULL === $args[1]->type) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMDocument::createEntityReference(): Argument #1 ($name) must be of type string, null given'
            );

            return self::boxNullResult($context);
        }

        $nameLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null !== $nameLit) {
            return self::materialize($context, $nameLit);
        }

        return self::materializeFromRuntime($context, self::loadStringArg($context, $args[1]));
    }

    public static function materialize(Context $context, string $name): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_STANDIN);
        self::ensurePropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        self::storeStringLiteral($context, $obj, self::PROP_NODE_NAME, $name);
        self::storeStringLiteral($context, $obj, self::PROP_TAG_NAME, self::TAG_KIND);
        // saveXML fetches textContent on every node (#32315). Entity-ref xmlNodeDump is `&name;`.
        self::storeStringLiteral($context, $obj, self::PROP_TEXT_CONTENT, '');

        return $obj;
    }

    private static function materializeFromRuntime(Context $context, Value $nameStr): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_STANDIN);
        self::ensurePropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        self::storeStringValue($context, $obj, self::PROP_NODE_NAME, $nameStr);
        self::storeStringLiteral($context, $obj, self::PROP_TAG_NAME, self::TAG_KIND);
        self::storeStringLiteral($context, $obj, self::PROP_TEXT_CONTENT, '');

        return $obj;
    }

    private static function ensurePropertyLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $classId
    ): void {
        foreach ([
            self::PROP_NODE_NAME,
            self::PROP_TAG_NAME,
            self::PROP_TEXT_CONTENT,
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
