<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link hook for enum_exists() (#1373, #16169).
 */
final class StringEnumExists
{
    public static function ensureLinked(Context $context): void
    {
        EnumExistsRuntime::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $nameStr): Value
    {
        return EnumExistsRuntime::invoke($context, $nameStr);
    }
}
