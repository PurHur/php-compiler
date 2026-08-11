<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * fnmatch() for compiled JIT/AOT modules (#30383, php-in-PHP).
 *
 * Leaf is `@fnmatch` → NestedJIT whitelist {@see fnmatch} →
 * {@see \PHPCompiler\JIT\Builtin\StringFnmatch::invokeNestedLeaf} (no kernel).
 * Keep NestedJIT TU small — no {@see VmFnmatchPure} pull (#579 stubs; chdir #29219 shape).
 * Returns int 0/1 (not bool) so NestedJIT return lowering uses __value__readLong
 * (#20603 / HashEquals i32 ABI).
 * php-src: ext/standard/fnmatch.c — PHP_FUNCTION(fnmatch)
 */
final class FnmatchJitHelper
{
    /** @return int 1 on match, 0 otherwise */
    public static function invokeArgv(string $pattern, string $filename, int $flags = 0): int
    {
        return @\fnmatch($pattern, $filename, $flags) ? 1 : 0;
    }
}
