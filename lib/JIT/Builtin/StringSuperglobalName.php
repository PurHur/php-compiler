<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT link hook for __compiler_is_superglobal_name — SuperglobalNameJitHelper PHP bridge (#9271).
 */
final class StringSuperglobalName
{
    public static function ensureLinked(Context $context): void
    {
        SuperglobalNameRuntime::ensureLinked($context);
    }
}
