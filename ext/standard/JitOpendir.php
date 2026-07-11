<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringOpendir;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** JIT/AOT helper for opendir() via OpendirJitHelper PHP (#15891). */
final class JitOpendir
{
    /** @return Value */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return StringOpendir::invoke($context, $pathStr);
    }
}
