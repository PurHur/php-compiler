<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chunk_split() for compiled JIT/AOT modules (#14626, php-in-PHP).
 *
 * SSOT: {@see VmString::chunkSplit()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(chunk_split)
 */
final class ChunkSplitJitHelper
{
    public static function chunkSplitArgv(string $string, int $length, string $separator): string
    {
        return VmString::chunkSplit($string, $length, $separator);
    }
}
