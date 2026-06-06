<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM body for __phpc_parse_url_* (mirrors VmString::parseUrl / former phpc_parse_url.c; #5913).
 */
final class ParseUrl
{
    public static function ensureLinked(Context $context): void
    {
        ParseUrlJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
