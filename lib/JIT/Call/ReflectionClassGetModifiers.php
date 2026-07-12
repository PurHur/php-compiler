<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionClassGetModifiersRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** ReflectionClass::getModifiers() — JIT/AOT (#18335, ext/reflection/php_reflection.c). */
final class ReflectionClassGetModifiers implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);
        $i64 = $context->getTypeFromString('int64');
        $nameStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
        $modifiers = ReflectionClassGetModifiersRuntime::invoke($context, $nameStr);
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $resultSlot, $modifiers);

        return $resultSlot;
    }
}
