<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chunk_split() NestedJIT/AOT SSOT (#30859 / re-#26992 / #33894).
 *
 * Peer {@see VmConvertUu} / htmlspecialchars recursive escapeFrom (#25345):
 * NestedJIT-bundle with {@see ChunkSplitJitHelper}. Prefer strlen/substr — not
 * `$s[$i]` / isset-length loops.
 *
 * Recursive offset walk (#30859) still miscompiled under thin AOT NestedJIT:
 * `chunk_split('abcd', 2, ':')` → `abcd:cd:` instead of Zend `ab:cd:` (#33894).
 * `str_split` + `implode` matches Zend under the same NestedJIT path.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(chunk_split)
 */
final class VmChunkSplit
{
    public static function chunkSplit(string $string, int $length, string $separator = "\r\n"): string
    {
        if ($length < 1) {
            throw new \ValueError('chunk_split(): Argument #2 ($length) must be greater than 0');
        }
        $byteLen = \strlen($string);
        if (0 === $byteLen) {
            return $separator;
        }

        // Zend-equivalent shape — NestedJIT-safe vs recursive substr walk (#33894).
        return \implode($separator, \str_split($string, $length)).$separator;
    }
}
