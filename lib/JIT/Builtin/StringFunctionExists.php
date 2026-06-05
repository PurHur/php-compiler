<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link hook for __compiler_builtin_function_exists (issue #5390, #1216).
 */
final class StringFunctionExists
{
    public static function ensureLinked(Context $context): void
    {
        FunctionExistsRuntime::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        FunctionExistsRuntime::implement($context);
    }
}
