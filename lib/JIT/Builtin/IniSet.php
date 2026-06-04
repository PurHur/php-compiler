<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** __compiler_ini_set LLVM body — {@see IniRuntime} (#5736, #1374). */
final class IniSet
{
    public static function implement(Context $context): void
    {
        IniRuntime::ensureLinked($context);
    }
}
