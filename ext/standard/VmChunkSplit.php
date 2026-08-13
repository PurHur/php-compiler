<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * chunk_split() NestedJIT/AOT SSOT (#30859 / re-#26992).
 *
 * Peer {@see VmConvertUu} / htmlspecialchars recursive escapeFrom (#25345):
 * NestedJIT-bundle with {@see ChunkSplitJitHelper}. Use strlen/substr — not
 * `$s[$i]` / isset-length loops. Prefer recursive offset walk over a mutating
 * `$i = $i + $length` loop (thin AOT NestedJIT reused the first chunk).
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

        return self::chunkFrom($string, 0, $byteLen, $length, $separator);
    }

    /**
     * Recursive offset walk — NestedJIT-safe vs mutating loop index (#30859 / peer #25345).
     */
    private static function chunkFrom(
        string $string,
        int $offset,
        int $byteLen,
        int $length,
        string $separator
    ): string {
        if ($offset >= $byteLen) {
            return '';
        }
        $remain = $byteLen - $offset;
        $chunkLen = $length;
        if ($chunkLen > $remain) {
            $chunkLen = $remain;
        }
        $chunk = \substr($string, $offset, $chunkLen);
        $next = $offset + $length;

        return $chunk.$separator.self::chunkFrom($string, $next, $byteLen, $length, $separator);
    }
}
