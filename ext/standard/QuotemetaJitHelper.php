<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * quotemeta() for compiled JIT/AOT modules (#14705, #21589, #27011, #30858, php-in-PHP).
 *
 * Thin argv bridge — algorithm in {@see VmQuotemeta}, NestedJIT-bundled with this file
 * (peer {@see ChunkSplitJitHelper} / #30859, {@see SoundexJitHelper} / #30790).
 * Solo NestedJIT of the former `$s[$i]` / isset-length helper SIGSEGV'd under thin AOT.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(quotemeta)
 */
final class QuotemetaJitHelper
{
    public static function quotemetaArgv(string $str): string
    {
        return VmQuotemeta::quotemeta($str);
    }
}
