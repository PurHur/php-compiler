<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for unitenum_exists() via UnitEnumExistsJitHelper PHP (#16169).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringUnitEnumExists;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitUnitEnumExists
{
    public static function invoke(Context $context, Value $nameStr): Value
    {
        return StringUnitEnumExists::invoke($context, $nameStr);
    }
}
