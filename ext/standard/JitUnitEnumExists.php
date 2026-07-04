<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringUnitEnumExists;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for unitenum_exists() — delegates to UnitEnumExistsJitHelper PHP (#6884, #16169). */
final class JitUnitEnumExists
{
    /** @return Value matches defined() / enum_exists() for JUMPIF truthiness */
    public static function invoke(Context $context, Value $nameStr): Value
    {
        return StringUnitEnumExists::invoke($context, $nameStr);
    }
}
