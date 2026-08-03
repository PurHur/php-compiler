<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * ReflectionConstant::getName() — JIT/AOT (#21551, #27303).
 *
 * Global constants store the name in `$constant` (PROP_CONSTANT_NAME); `$name` is empty
 * (see VM Construct / php_reflection.c). Slot holds a heap __value__* string box.
 */
final class ReflectionConstantGetName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        [$cstr, $len] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionConstant',
            ReflectionSupport::PROP_CONSTANT_NAME
        );

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
    }
}
