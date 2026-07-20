<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** ReflectionConstant::getShortName() — JIT/AOT (#21551, ext/reflection/php_reflection.c). */
final class ReflectionConstantGetShortName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::stringPropertyAsCstr($context, $obj, 'ReflectionConstant', 'constant');
        $short = ReflectionSetup::shortNameFromCstr($context, $cstr, $len);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($short['len'], $i64),
            $short['cstr']
        );
    }
}
