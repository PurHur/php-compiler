<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM body for strnatcmp (natural-order compare; mirrors VmString::strnatcmp).
 */
final class StringStrnatcmp
{
    public static function ensureLinked(Context $context): void
    {
        StringNaturalCompareJit::implementStrnatcmp($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
