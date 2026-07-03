<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for readlink() via ReadlinkJitHelper PHP (#15353).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringReadlink;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitReadlink
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return StringReadlink::invoke($context, $pathStr);
    }
}
