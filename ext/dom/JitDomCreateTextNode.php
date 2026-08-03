<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Compile-time text-child stand-in for user-script AOT (#18951, #27260).
 *
 * Uses a DOMElement stand-in (peer {@see JitDomCreateComment}) because allocating an
 * unregistered DOMText class aborts LLVM codegen in standalone AOT. Slot layout exposes
 * nodeName / nodeValue / textContent / data for property reads after loadXML whitespace.
 */
final class JitDomCreateTextNode
{
    private const CLASS_STANDIN = 'DOMElement';

    private const PROP_NODE_NAME = 'nodeName';

    private const PROP_NODE_VALUE = 'nodeValue';

    private const PROP_TEXT_CONTENT = 'textContent';

    private const PROP_DATA = 'data';

    public static function materialize(Context $context, string $data = ''): Value
    {
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
}
