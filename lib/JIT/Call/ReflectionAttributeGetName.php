<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

final class ReflectionAttributeGetName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $nameVar = $context->type->object->propertyFetch($obj, 'ReflectionAttribute', 'name');
        $valuePtr = $context->helper->loadValue($nameVar);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $context->builder->pointerCast($valuePtr, $context->getTypeFromString('__value__*'))
        );
    }
}
