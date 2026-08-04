<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionEnumJitHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** ReflectionEnum::getBackingType() — JIT/AOT (#27515, #9886, php_reflection.c). */
final class ReflectionEnumGetBackingType implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);

        return ReflectionEnumJitHelper::emitGetBackingType($context, $obj);
    }
}
