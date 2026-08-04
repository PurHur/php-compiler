<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/** ReflectionNamedType::getName() — JIT/AOT (#27515, #3355). */
final class ReflectionNamedTypeGetName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionNamedType',
            ReflectionSupport::PROP_TYPE_NAME
        );
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
    }
}
