<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for rmdir() via RmdirJitHelper PHP (#15481).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringRmdir;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitRmdir
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return StringRmdir::invoke($context, $pathStr);
    }
}
