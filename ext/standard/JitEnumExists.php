<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringEnumExists;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for enum_exists() — delegates to EnumExistsJitHelper PHP (#1373, #16169). */
final class JitEnumExists
{
    /** @return Value matches defined() / array_key_exists() for JUMPIF truthiness */
    public static function invoke(Context $context, Value $nameStr): Value
    {
        return StringEnumExists::invoke($context, $nameStr);
    }
}
