<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * __compiler_ini_set LLVM body for non-standalone JIT (issue #1374).
 * Standalone AOT uses lib/AOT/runtime/phpc_ini_set.c.
 */
final class IniSet
{
    public static function implement(Context $context): void
    {
        IniRuntime::ensureLinked($context);
    }
}
