<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chunk_split() for compiled JIT/AOT modules (#14626, #26992, #30859, php-in-PHP).
 *
 * Thin argv bridge — algorithm in {@see VmChunkSplit}, NestedJIT-bundled with this file
 * (peer {@see ConvertUuJitHelper} / #30811, {@see SoundexJitHelper} / #30790).
 * Solo NestedJIT of the former `$s[$i]` / isset-length helper SIGSEGV'd under thin AOT.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(chunk_split)
 */
final class ChunkSplitJitHelper
{
    public static function chunkSplitArgv(string $string, int $length, string $separator): string
    {
        return VmChunkSplit::chunkSplit($string, $length, $separator);
    }
}
