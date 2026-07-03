<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT bridge for strncasecmp — delegates to {@see StringCaseCompare} (#15225).
 */
final class StringStrncasecmp
{
    public static function ensureLinked(Context $context): void
    {
        StringCaseCompare::ensureStrncasecmpLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
