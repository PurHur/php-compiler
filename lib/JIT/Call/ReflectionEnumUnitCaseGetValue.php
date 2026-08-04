<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionEnumJitHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * ReflectionEnumUnitCase::getValue() / ReflectionEnumBackedCase::getValue() — JIT/AOT (#27515).
 *
 * Returns the enum case object (same shape as VM ReflectionEnumUnitCaseGetValue / php_reflection.c).
 */
final class ReflectionEnumUnitCaseGetValue implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);

        return ReflectionEnumJitHelper::emitGetValue($context, $obj);
    }
}
