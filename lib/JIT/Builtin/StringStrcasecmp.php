<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT bridge for strcasecmp — delegates to {@see StringCaseCompare} (#15225).
 */
final class StringStrcasecmp
{
    public static function ensureLinked(Context $context): void
    {
        StringCaseCompare::ensureStrcasecmpLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
