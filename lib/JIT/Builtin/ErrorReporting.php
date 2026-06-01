<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * __compiler_error_reporting JIT MCJIT body — link shared AOT runtime (#3220).
 */
final class ErrorReporting
{
    public static function implement(Context $context): void
    {
        IniRuntime::ensureLinked($context);
    }
}
