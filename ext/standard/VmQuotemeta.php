<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * quotemeta() NestedJIT/AOT SSOT (#30858 / re-#27011).
 *
 * Peer {@see VmSoundex} / {@see VmWordwrap}: NestedJIT-bundle with
 * {@see QuotemetaJitHelper}. Use strlen/substr — not `$s[$i]` / isset-length.
 * Prefer `$i = advanceIdx($i, 1)` over `++$i` / deep per-byte recursion
 * (recursion heap-corrupts and SIGSEGVs later builtins under thin AOT).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(quotemeta)
 */
final class VmQuotemeta
{
    public static function quotemeta(string $string): string
    {
        $byteLen = \strlen($string);
        if (0 === $byteLen) {
            return '';
        }
        $out = '';
        $i = 0;
        while ($i < $byteLen) {
            $ch = \substr($string, $i, 1);
            $out .= self::escapeChar($ch);
            $i = self::advanceIdx($i, 1);
        }

        return $out;
    }

    /**
     * NestedJIT-safe index advance (#26815 / peer metaphone / soundex / wordwrap).
     * Bare `++$i` / `$i = $i + 1` in hot loops has SIGSEGV'd or no-op'd under NestedJIT AOT.
     */
    private static function advanceIdx(int $idx, int $delta): int
    {
        $i = 0;
        while ($i < $delta) {
            $idx = $idx + 1;
            $i = $i + 1;
        }

        return $idx;
    }

    /** NestedJIT-safe quotemeta escape (php-src string.c c); peer StrRot13JitHelper::rot13Char. */
    private static function escapeChar(string $ch): string
    {
        return match ($ch) {
            '.' => '\\.',
            '\\' => '\\\\',
            '+' => '\\+',
            '*' => '\\*',
            '?' => '\\?',
            '[' => '\\[',
            ']' => '\\]',
            '^' => '\\^',
            '(' => '\\(',
            ')' => '\\)',
            '$' => '\\$',
            default => $ch,
        };
    }
}
