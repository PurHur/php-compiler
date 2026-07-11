<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT bridge for strnatcmp — delegates to {@see StringNaturalCompare} (#13535).
 */
final class StringStrnatcmp
{
    public static function ensureLinked(Context $context): void
    {
        StringNaturalCompare::ensureStrnatcmpLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
