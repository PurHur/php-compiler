<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

final class ReflectionClassGetName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        [$cstr, $len] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);
        $len64 = $context->builder->zExt($len, $i64);

        return $context->builder->call($context->lookupFunction('__string__init'), $len64, $cstr);
    }
}
