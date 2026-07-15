<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Compile-time text-child stand-in for user-script AOT live mutation (#18951). */
final class JitDomCreateTextNode
{
    private const CLASS_STANDIN = 'DOMElement';

    private const PROP_NODE_NAME = 'nodeName';

    public static function materialize(Context $context): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_STANDIN);
        if (!$objectType->hasProperty($classId, self::PROP_NODE_NAME)) {
            $objectType->defineProperty($classId, self::PROP_NODE_NAME, JITVariable::TYPE_STRING);
        }

        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $nameStr = $context->builder->load($context->constantStringFromString('#text'));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $nameStr
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_STANDIN, self::PROP_NODE_NAME),
            $propVar,
            JITVariable::TYPE_STRING
        );

        return $obj;
    }
}
