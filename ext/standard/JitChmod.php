<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for chmod() via ChmodJitHelper PHP (#15458).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringChmod;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitChmod
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr, Value $modeI32): Value
    {
        return StringChmod::invoke($context, $pathStr, $modeI32);
    }
}
