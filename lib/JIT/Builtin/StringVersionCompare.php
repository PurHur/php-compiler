<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM body for __compiler_version_compare (php-src ext/standard/versioning.c; #6277).
 */
final class StringVersionCompare
{
    public static function ensureLinked(Context $context): void
    {
        StringVersionCompareJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
