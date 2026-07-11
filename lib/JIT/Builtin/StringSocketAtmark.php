<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link facade for socket_atmark() — SocketAtmarkJitHelper (#9215).
 */
final class StringSocketAtmark
{
    public static function ensureLinked(Context $context): void
    {
        SocketAtmarkRuntime::ensureLinked($context);
    }

    public static function helperFunction(Context $context): LlvmFunction
    {
        return SocketAtmarkRuntime::helperFunction($context);
    }
}
