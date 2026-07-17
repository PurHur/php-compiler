<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT MCJIT body for __compiler_grapheme_str_split — embed PHP helper (#19964).
 *
 * php-src: ext/intl/grapheme/grapheme_string.c
 */
final class StringGraphemeStrSplit
{
    public static function ensureLinked(Context $context): void
    {
        GraphemeStrSplitRuntime::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        GraphemeStrSplitRuntime::ensureStandaloneBodies($context);
    }
}
