<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitExplode;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT emit for explode() runtime path (#14750 / #27660).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\ExplodeJitHelper} aborts under thin AOT:
 * unbound {@see VmString} stubs become null, and NestedJIT {@see HashTable} construction
 * segfaults at compile time (peer #26956 RangeInt / #27078 ParseUrl). Runtime therefore
 * uses LLVM emission via {@see JitExplode::explode()} — same shape as {@see JitStrSplit}.
 *
 * Compile-time literals still const-fold through {@see JitExplode::buildPackedStrings()}.
 * Host/PHPUnit SSOT remains ExplodeJitHelper (no NestedJIT).
 *
 * php-src: ext/standard/string.c — php_explode()
 */
final class StringExplode
{
    public static function ensureLinked(Context $context): void
    {
        // JitExplode::explode pulls JitStringSearch / string_trim as needed.
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(
        Context $context,
        Value $delimiter,
        Value $haystack,
        Value $limit
    ): Value {
        return JitExplode::explode($context, $delimiter, $haystack, $limit);
    }
}
