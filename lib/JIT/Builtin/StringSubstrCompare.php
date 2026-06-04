<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM body for substr_compare (mirrors VmString::substr_compare).
 */
final class StringSubstrCompare
{
    public static function ensureLinked(Context $context): void
    {
        StringSubstrCompareJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
