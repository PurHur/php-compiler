<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Thin facade for tests / explicit link of the exception-handler stack (#4311, #3146).
 *
 * Prefer {@see ExceptionHandlerJitRuntime::ensureLinked} at call sites. Do not invoke from
 * {@see Type::initialize} — that always-on path was dropped (#33842; peer #33798 ObOutput).
 */
final class ExceptionHandlerOutput
{
    public static function registerExternals(Context $context): void
    {
        ExceptionHandlerJitRuntime::ensureLinked($context);
    }
}
