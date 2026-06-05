<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM body for strtok (php-src ext/standard/string.c; #6111).
 */
final class StringStrtok
{
    public static function ensureLinked(Context $context): void
    {
        StringStrtokJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
