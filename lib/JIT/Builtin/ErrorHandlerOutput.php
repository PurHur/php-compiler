<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** LLVM error-handler stack for JIT/AOT (issue #5316, #1379, #1492). */
final class ErrorHandlerOutput
{
    public static function registerExternals(Context $context): void
    {
        ErrorHandlerJitRuntime::ensureLinked($context);
    }
}
