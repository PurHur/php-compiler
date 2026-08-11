<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringFnmatch;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT helper for fnmatch() via FnmatchJitHelper PHP (#30383, #3189/#6721).
 */
final class JitFnmatch
{
    /** @return Value i1 — true when pattern matches */
    public static function invoke(Context $context, Value $pattern, Value $string, Value $flagsI32): Value
    {
        return StringFnmatch::invoke($context, $pattern, $string, $flagsI32);
    }
}
