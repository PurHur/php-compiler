<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for realpath() via RealpathJitHelper PHP (#15323).
 *
 * Failure returns an empty string; PHP's empty string compares equal to false with ==.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringRealpath;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitRealpath
{
    public static function resolve(Context $context, Value $str): Value
    {
        return StringRealpath::invoke($context, $str);
    }
}
