<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for enum_exists() via EnumExistsJitHelper PHP (#16169).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringEnumExists;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitEnumExists
{
    public static function invoke(Context $context, Value $nameStr): Value
    {
        return StringEnumExists::invoke($context, $nameStr);
    }
}
