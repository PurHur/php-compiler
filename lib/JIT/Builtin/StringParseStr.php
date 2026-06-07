<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM body for __compiler_parse_str (mirrors VmParseStr merge semantics; #6013).
 */
final class StringParseStr
{
    public static function ensureLinked(Context $context): void
    {
        StringParseStrJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
