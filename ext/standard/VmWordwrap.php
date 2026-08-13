<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * wordwrap() NestedJIT/AOT SSOT (#30812).
 *
 * Peer {@see VmSoundex} / {@see VmConvertUu}: NestedJIT-bundle with
 * {@see WordwrapJitHelper}. Use strlen/substr — not `$s[$i]` / isset($s[$i])
 * (thin AOT NestedJIT SIGSEGV after c:main_before_php). Index advances go through
 * {@see advanceIdx} — bare `$i = $i + 1` in the hot loop has NestedJIT'd as a
 * no-op (first-byte repeat) under thin AOT (#30812 bisect).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(wordwrap) / php_wordwrap
 */
final class VmWordwrap
{
    /**
     * @param int $cut 0/1 — avoid NestedJIT bool ABI (#30812)
     */
    public static function wrap(string $text, int $width, string $break, int $cut): string
    {
        $cutBool = 0 !== $cut;
        $len = \strlen($text);
        if (0 === $len) {
            return '';
        }
        $breakLen = \strlen($break);
        if (0 === $breakLen) {
            // php-src string.c — keep literal (no VmString call; NestedJIT isolation) (#29291)
            throw new \ValueError('wordwrap(): Argument #3 ($break) must not be empty');
        }
        if (0 === $width && $cutBool) {
            throw new \ValueError('wordwrap(): Argument #4 ($cut_long_words) cannot be true when argument #2 ($width) is 0');
        }

        if ($cutBool) {
            if ($width < 1) {
                return $text;
            }

            return self::wordwrapGeneral($text, $len, $width, $break, $breakLen, 1);
        }

        // Always use general path under NestedJIT — the single-byte fast path
        // (segStart / lastspace slices) NestedJIT'd wrong bytes + intermittent
        // SIGSEGV under thin AOT (#30812). General path matches Zend for cut=false.
        return self::wordwrapGeneral($text, $len, $width, $break, $breakLen, 0);
    }

    /**
     * NestedJIT-safe index advance (#26815 / peer metaphone / soundex).
     * Bare `$i = $i + 1` in wordwrap hot loops NestedJIT'd as a no-op (#30812).
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

    private static function charAt(string $s, int $len, int $index): string
    {
        if ($index < 0 || $index >= $len) {
            return '';
        }

        return \substr($s, $index, 1);
    }

    /** General path: multi-byte break and cut true/false (php-src php_wordwrap). */
    private static function wordwrapGeneral(
        string $text,
        int $len,
        int $width,
        string $break,
        int $breakLen,
        int $cut
    ): string {
        $cutBool = 0 !== $cut;
        $out = '';
        $laststart = 0;
        $lastspace = 0;
        $current = 0;
        while ($current < $len) {
            $ch = self::charAt($text, $len, $current);
            $b0 = self::charAt($break, $breakLen, 0);
            if ($current + $breakLen <= $len
                && $ch === $b0
                && 0 === self::byteCompareN($text, $current, $break, 0, $breakLen)) {
                $out = $out.self::byteSlice($text, $laststart, $current - $laststart + $breakLen);
                $current = self::advanceIdx($current, $breakLen);
                $laststart = $current;
                $lastspace = $current;
            } elseif (' ' === $ch) {
                if ($current - $laststart >= $width) {
                    $out = $out.self::byteSlice($text, $laststart, $current - $laststart);
                    $out = $out.$break;
                    $laststart = $current + 1;
                }
                $lastspace = $current;
                $current = self::advanceIdx($current, 1);
            } elseif ($cutBool && $current - $laststart >= $width && $laststart >= $lastspace) {
                $out = $out.self::byteSlice($text, $laststart, $current - $laststart);
                $out = $out.$break;
                $laststart = $current;
                $lastspace = $current;
                $current = self::advanceIdx($current, 1);
            } elseif ($current - $laststart >= $width && $laststart < $lastspace) {
                $out = $out.self::byteSlice($text, $laststart, $lastspace - $laststart);
                $out = $out.$break;
                $laststart = $lastspace + 1;
                $lastspace = $laststart;
                $current = self::advanceIdx($current, 1);
            } else {
                $current = self::advanceIdx($current, 1);
            }
        }
        if ($laststart < $len) {
            $out = $out.self::byteSlice($text, $laststart, $len - $laststart);
        }

        return $out;
    }

    private static function byteSlice(string $s, int $start, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        return \substr($s, $start, $length);
    }

    private static function byteCompareN(string $a, int $aOff, string $b, int $bOff, int $n): int
    {
        $i = 0;
        while ($i < $n) {
            if (\substr($a, $aOff + $i, 1) !== \substr($b, $bOff + $i, 1)) {
                return 1;
            }
            $i = self::advanceIdx($i, 1);
        }

        return 0;
    }
}
