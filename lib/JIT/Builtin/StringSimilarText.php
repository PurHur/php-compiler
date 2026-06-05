<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM body for phpc_similar_text (php-src ext/standard/string.c; #5173).
 */
final class StringSimilarText
{
    public static function ensureLinked(Context $context): void
    {
        StringSimilarTextJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
