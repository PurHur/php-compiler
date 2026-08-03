<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for strnatcmp/strnatcasecmp via LLVM bodies (#5517, #26975).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\NaturalCompareJitHelper} returns 0 under thin
 * standalone AOT (strlen/ord/loop lowering holes). Emit the VmString algorithm as LLVM
 * instead — peer MultisortRuntime / NaturalSortRuntime (#26908 / #26975).
 *
 * SSOT (VM): {@see \PHPCompiler\ext\standard\VmString::strnatcmp()} /
 * {@see \PHPCompiler\ext\standard\VmString::strnatcasecmp()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strnatcmp) / strnatcasecmp
 */
final class StringNaturalCompare
{
    public static function ensureStrnatcmpLinked(Context $context): void
    {
        StringNaturalCompareJit::implementStrnatcmp($context);
    }

    public static function ensureStrnatcasecmpLinked(Context $context): void
    {
        StringNaturalCompareJit::implementStrnatcasecmp($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureStrnatcmpLinked($context);
        self::ensureStrnatcasecmpLinked($context);
    }
}
