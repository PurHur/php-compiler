<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT bridge for strnatcasecmp — delegates to {@see StringNaturalCompare} (#13535).
 */
final class StringStrnatcasecmp
{
    public static function ensureLinked(Context $context): void
    {
        StringNaturalCompare::ensureStrnatcasecmpLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
