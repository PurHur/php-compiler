<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_replace()/str_ireplace() PHP helper (#14779) — SSOT via VmString for host/VM tests.
 *
 * User-script AOT scalar path uses inline LLVM in {@see JitStrReplace} (#23912): NestedJIT of
 * this helper miscompiles string length/indexing under thin AOT (wrong output or segfault).
 *
 * php-src: ext/standard/string.c — php_str_replace, php_str_replace_in_subject
 */
final class StrReplaceJitHelper
{
    private static int $lastCount = 0;

    public static function replaceArgv(string $search, string $replace, string $subject): string
    {
        $count = 0;
        $result = VmString::strReplace($search, $replace, $subject, $count);
        self::$lastCount = $count;

        return $result;
    }

    public static function ireplaceArgv(string $search, string $replace, string $subject): string
    {
        $count = 0;
        $result = VmString::strIreplace($search, $replace, $subject, $count);
        self::$lastCount = $count;

        return $result;
    }

    public static function takeLastCount(): int
    {
        return self::$lastCount;
    }
}
