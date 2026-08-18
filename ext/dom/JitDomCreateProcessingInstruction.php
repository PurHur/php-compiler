<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script AOT materialization for DOMProcessingInstruction (#32331).
 *
 * Uses a DOMElement stand-in (peer {@see JitDomCreateComment}) because allocating an
 * unregistered DOMProcessingInstruction class aborts LLVM codegen in standalone AOT.
 * {@see TAG_KIND} (`#pi`) is the saveXML discriminator — Zend `nodeName` is the PI target.
 *
 * php-src: ext/dom/document.c PHP_METHOD(DOMDocument, createProcessingInstruction) → xmlNewDocPI
 */
final class JitDomCreateProcessingInstruction
{
    private const CLASS_STANDIN = 'DOMElement';

    /** Internal saveXML discriminator; not a Zend PI nodeName. */
    public const TAG_KIND = '#pi';

    private const PROP_NODE_NAME = 'nodeName';

    private const PROP_TAG_NAME = 'tagName';

    private const PROP_NODE_VALUE = 'nodeValue';

    private const PROP_TEXT_CONTENT = 'textContent';

    private const PROP_DATA = 'data';

    private const PROP_TARGET = 'target';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::createProcessingInstruction() expects receiver and target');
        }

        if ($context->callerStrictTypes && JITVariable::TYPE_NULL === $args[1]->type) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMDocument::createProcessingInstruction(): Argument #1 ($target) must be of type string, null given'
            );

            return self::boxNullResult($context);
        }

        $dataArg = $args[2] ?? null;
        if (null !== $dataArg && $context->callerStrictTypes && JITVariable::TYPE_NULL === $dataArg->type) {
            \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
            \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'DOMDocument::createProcessingInstruction(): Argument #2 ($data) must be of type string, null given'
            );

            return self::boxNullResult($context);
        }

        $targetLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $dataLit = '';
        if (null !== $dataArg) {
            $dataLit = JitStringBuiltinArg::compileTimeLiteral($dataArg) ?? $dataArg->compileTimeString;
        }
        if (null !== $targetLit && (null === $dataArg || null !== $dataLit)) {
            return self::materialize($context, $targetLit, (string) $dataLit);
        }

        $targetStr = self::loadStringArg($context, $args[1]);
        $dataStr = null === $dataArg
            ? $context->builder->load($context->constantStringFromString(''))
            : self::loadStringArg($context, $dataArg);

        return self::materializeFromRuntime($context, $targetStr, $dataStr);
    }

    public static function materialize(Context $context, string $target, string $data): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_STANDIN);
        self::ensurePropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        self::storeStringLiteral($context, $obj, self::PROP_NODE_NAME, $target);
        self::storeStringLiteral($context, $obj, self::PROP_TAG_NAME, self::TAG_KIND);
        self::storeStringLiteral($context, $obj, self::PROP_TARGET, $target);
        self::storeStringLiteral($context, $obj, self::PROP_NODE_VALUE, $data);
        self::storeStringLiteral($context, $obj, self::PROP_TEXT_CONTENT, $data);
        self::storeStringLiteral($context, $obj, self::PROP_DATA, $data);

        return $obj;
    }

    private static function materializeFromRuntime(Context $context, Value $targetStr, Value $dataStr): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_STANDIN);
        self::ensurePropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        self::storeStringValue($context, $obj, self::PROP_NODE_NAME, $targetStr);
        self::storeStringLiteral($context, $obj, self::PROP_TAG_NAME, self::TAG_KIND);
        self::storeStringValue($context, $obj, self::PROP_TARGET, $targetStr);
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
            self::PROP_TAG_NAME,
            self::PROP_NODE_VALUE,
            self::PROP_TEXT_CONTENT,
            self::PROP_DATA,
            self::PROP_TARGET,
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
