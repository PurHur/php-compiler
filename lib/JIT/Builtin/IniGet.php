<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** __compiler_ini_get LLVM body — {@see IniRuntime} (#5736, #1374, #1492). */
final class IniGet
{
    public static function implement(Context $context): void
    {
        IniRuntime::ensureLinked($context);
    }
}
