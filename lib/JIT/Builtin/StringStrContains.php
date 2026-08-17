<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\VM\VmStringCompare;
use PHPLLVM\Value;

/**
 * JIT/AOT link for str_contains/str_starts_with/str_ends_with (#14768, #24161, #26796).
 *
 * All three use libc memcmp/memmem via {@see VmStringCompare} — NestedJIT of
 * StrContainsJitHelper bool returns is wrong under AOT (always-false starts/ends
 * #24161; always-true contains #26796 / #15704).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c
 */
final class StringStrContains
{
    public static function ensureLinked(Context $context): void
    {
        self::ensureMemcmp($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invokeContains(Context $context, Value $haystack, Value $needle): Value
    {
        self::ensureMemcmp($context);

        return VmStringCompare::containsIdentical($context, $haystack, $needle);
    }

    public static function invokeStartsWith(Context $context, Value $haystack, Value $needle): Value
    {
        self::ensureMemcmp($context);

        return VmStringCompare::prefixIdentical($context, $haystack, $needle);
    }

    public static function invokeEndsWith(Context $context, Value $haystack, Value $needle): Value
    {
        self::ensureMemcmp($context);

        return VmStringCompare::suffixIdentical($context, $haystack, $needle);
    }

    private static function ensureMemcmp(Context $context): void
    {
        // memcmp(3) via LibcExtern::ensureMemcmpDecl after always-on drop (#31954).
        LibcExtern::ensureMemcmpDecl($context);
    }
}
