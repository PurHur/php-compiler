<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for chdir() via ChdirJitHelper PHP (#21147).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringChdir;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

final class JitChdir
{
    /** @return Value
     * true when chdir succeeds */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        return StringChdir::invoke($context, $pathStr);
    }
}
