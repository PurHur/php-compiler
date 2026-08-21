<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT link hook for __compiler_is_superglobal_name — SuperglobalNameJitHelper PHP bridge (#9271, #33235).
 *
 * Call-site / Type::initialize {@see ensureLinked} before lookup (Type always-on shell dropped).
 */
final class StringSuperglobalName
{
    public static function ensureLinked(Context $context): void
    {
        SuperglobalNameRuntime::ensureLinked($context);
    }
}
