<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** Ensures weakref registry LLVM is linked (#3667, #5684). */
final class WeakRefRuntime
{
    public static function ensureLinked(Context $context): void
    {
        WeakRefRegistryRuntime::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        WeakRefRegistryRuntime::implement($context);
    }
}
