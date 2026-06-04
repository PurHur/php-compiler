<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM body for strnatcasecmp (case-insensitive natural-order compare).
 */
final class StringStrnatcasecmp
{
    public static function ensureLinked(Context $context): void
    {
        StringNaturalCompareJit::implementStrnatcasecmp($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
