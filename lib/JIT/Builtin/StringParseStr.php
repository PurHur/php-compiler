<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT link for __compiler_parse_str — PHP helper on embed, LLVM on standalone (#9295).
 */
final class StringParseStr
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            StringParseStrJit::implement($context);

            return;
        }

        ParseStrRuntime::implement($context);
        StringParseStrJit::ensureSubhelpers($context);
    }
}
