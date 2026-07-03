<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringMkdir;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** JIT/AOT helper for mkdir() via MkdirJitHelper PHP (#15586). */
final class JitMkdir
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr, Value $modeLong, Value $recursiveBool): Value
    {
        return StringMkdir::invoke($context, $pathStr, $modeLong, $recursiveBool);
    }
}
