<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT link for __compiler_parse_str — ParseStrRuntime PHP on embed and standalone (#9295, #13360).
 */
final class StringParseStr
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        ParseStrRuntime::implement($context);
        StringParseStrJit::ensureSubhelpers($context);
    }
}
