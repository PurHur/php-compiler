<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM JIT/AOT helper for str_ireplace() — delegates to {@see JitStrReplace}. */
final class JitStrIreplace
{
    public static function replace(Context $context, Value $search, Value $replace, Value $subject): Value
    {
        return JitStrReplace::replace($context, $search, $replace, $subject, true);
    }
}
