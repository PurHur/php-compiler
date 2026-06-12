<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** LLVM exception-handler stack for JIT/AOT (issue #4311, #3146). */
final class ExceptionHandlerOutput
{
    public static function registerExternals(Context $context): void
    {
        ExceptionHandlerJitRuntime::ensureLinked($context);
    }
}
