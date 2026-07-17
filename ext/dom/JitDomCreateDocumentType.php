<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script AOT materialization for DOMImplementation::createDocumentType() (#19797).
 *
 * Uses a DOMElement stand-in (same pattern as {@see JitDomCreateComment}) because
 * allocating an unregistered DOMDocumentType class aborts LLVM codegen in standalone AOT.
 * Slot layout exposes name / publicId / systemId / nodeName for property reads.
 *
 * php-src: ext/dom/domimplementation.stub.php
 *   createDocumentType(string $qualifiedName, string $publicId = "", string $systemId = "")
 */
final class JitDomCreateDocumentType
{
    private const CLASS_STANDIN = 'DOMElement';

    private const PROP_NODE_NAME = 'nodeName';

    private const PROP_NAME = 'name';

    private const PROP_PUBLIC_ID = 'publicId';

    private const PROP_SYSTEM_ID = 'systemId';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'DOMImplementation::createDocumentType() expects at least 1 argument, 0 given'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'DOMImplementation::createDocumentType() expects at most 3 arguments, %d given',
                $argc
            ));
        }

        $nameLit = self::compileTimeStringArg($args[1]);
        $publicLit = '';
        $systemLit = '';
        $allLiteral = null !== $nameLit;
        if ($argc >= 2) {
            $publicLit = self::compileTimeStringArg($args[2]);
            $allLiteral = $allLiteral && null !== $publicLit;
        }
        if ($argc >= 3) {
            $systemLit = self::compileTimeStringArg($args[3]);
            $allLiteral = $allLiteral && null !== $systemLit;
        }

        if ($allLiteral) {
            return self::materialize(
                $context,
                (string) $nameLit,
                (string) ($publicLit ?? ''),
                (string) ($systemLit ?? '')
            );
        }

        return self::materializeFromRuntimeArgs($context, $args, $argc);
    }

    public static function materialize(
        Context $context,
        string $qualifiedName,
        string $publicId,
        string $systemId
    ): Value {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_STANDIN);
        self::ensurePropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        self::storeStringLiteral($context, $obj, self::PROP_NODE_NAME, $qualifiedName);
        self::storeStringLiteral($context, $obj, self::PROP_NAME, $qualifiedName);
        self::storeStringLiteral($context, $obj, self::PROP_PUBLIC_ID, $publicId);
        self::storeStringLiteral($context, $obj, self::PROP_SYSTEM_ID, $systemId);

        return $obj;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function materializeFromRuntimeArgs(
        Context $context,
        array $args,
        int $argc
    ): Value {
        $nameStr = self::loadStringArg($context, $args[1]);
        $publicStr = $argc >= 2
            ? self::loadStringArg($context, $args[2])
            : $context->builder->load($context->constantStringFromString(''));
        $systemStr = $argc >= 3
            ? self::loadStringArg($context, $args[3])
            : $context->builder->load($context->constantStringFromString(''));

        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_STANDIN);
        self::ensurePropertyLayout($objectType, $classId);

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        self::storeStringValue($context, $obj, self::PROP_NODE_NAME, $nameStr);
        self::storeStringValue($context, $obj, self::PROP_NAME, $nameStr);
        self::storeStringValue($context, $obj, self::PROP_PUBLIC_ID, $publicStr);
        self::storeStringValue($context, $obj, self::PROP_SYSTEM_ID, $systemStr);

        return $obj;
    }

    private static function compileTimeStringArg(JITVariable $arg): ?string
    {
        return JitStringBuiltinArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;
    }

    private static function ensurePropertyLayout(
        \PHPCompiler\JIT\Builtin\Type\Object_ $objectType,
        int $classId
    ): void {
        foreach ([
            self::PROP_NODE_NAME,
            self::PROP_NAME,
            self::PROP_PUBLIC_ID,
            self::PROP_SYSTEM_ID,
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
}
