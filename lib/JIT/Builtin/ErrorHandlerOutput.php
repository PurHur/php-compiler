<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Thin facade for tests / explicit link of the error-handler stack (#5316, #1379, #1492).
 *
 * Prefer {@see ErrorHandlerJitRuntime::ensureLinked} at call sites. Do not invoke from
 * {@see Type::initialize} — that always-on path was dropped (#33842; peer #33798 ObOutput).
 */
final class ErrorHandlerOutput
{
    public static function registerExternals(Context $context): void
    {
        ErrorHandlerJitRuntime::ensureLinked($context);
    }
}
