<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link hook for unitenum_exists() (#6884, #16169).
 */
final class StringUnitEnumExists
{
    public static function ensureLinked(Context $context): void
    {
        UnitEnumExistsRuntime::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $nameStr): Value
    {
        return UnitEnumExistsRuntime::invoke($context, $nameStr);
    }
}
