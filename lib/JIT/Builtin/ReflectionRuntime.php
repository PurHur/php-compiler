<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * Ensure reflection attribute lookup symbols exist for JIT/AOT (#1936, #5621).
 *
 * Attribute tables are lowered in PHP via {@see AttributeRegistryLowering}; no C runtime.
 */
final class ReflectionRuntime
{
    public static function ensureLinked(Context $context): void
    {
        AttributeRegistryLowering::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
