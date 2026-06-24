<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM body for __phpc_parse_url_* via ParseUrlJitHelper PHP (#9358, #5913).
 */
final class ParseUrl
{
    public static function ensureLinked(Context $context): void
    {
        ParseUrlRuntime::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
