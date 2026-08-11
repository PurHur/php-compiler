<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitNaturalCompareKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for strnatcmp/strnatcasecmp via {@see JitNaturalCompareKernel} (#30088).
 *
 * Thin orchestrator — LLVM algorithm lives in ext/standard (quarantine peer StreamMode #19794).
 * NestedJIT {@see \PHPCompiler\ext\standard\NaturalCompareJitHelper} still returns 0 under thin
 * AOT (#26975); do not route through JitVmHelperLink until NestedJIT string loops are fixed.
 *
 * SSOT (VM): {@see \PHPCompiler\ext\standard\VmString::strnatcmp()} /
 * {@see \PHPCompiler\ext\standard\VmString::strnatcasecmp()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(strnatcmp) / strnatcasecmp
 */
final class StringNaturalCompare
{
    public static function ensureStrnatcmpLinked(Context $context): void
    {
        JitNaturalCompareKernel::implementStrnatcmp($context);
    }

    public static function ensureStrnatcasecmpLinked(Context $context): void
    {
        JitNaturalCompareKernel::implementStrnatcasecmp($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureStrnatcmpLinked($context);
        self::ensureStrnatcasecmpLinked($context);
    }
}
