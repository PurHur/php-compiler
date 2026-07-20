<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** ReflectionConstant::getName() — JIT/AOT (#21551, ext/reflection/php_reflection.c). */
final class ReflectionConstantGetName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        // Slot holds heap __value__* from emitSetStringPropertyFromCstr (see ReflectionSetup).
        $nameVar = $context->type->object->propertyFetch($obj, 'ReflectionConstant', 'constant');
        $valuePtr = $context->helper->loadValue($nameVar);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $context->builder->pointerCast($valuePtr, $context->getTypeFromString('__value__*'))
        );
    }
}
