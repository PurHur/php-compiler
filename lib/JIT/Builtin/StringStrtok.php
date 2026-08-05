<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for phpc_strtok (#6111, #9812, #27645).
 *
 * Body: {@see StringStrtokJit} — LLVM module globals (NestedJIT helper aborts on thin AOT).
 * php-src: ext/standard/string.c — PHP_FUNCTION(strtok)
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
