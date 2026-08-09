<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * wordwrap() for compiled JIT/AOT modules (#14565, #26904, php-in-PHP).
 *
 * Self-contained (no call into VmString) so NestedJIT / helper-runtime units are not
 * ExternalMethod-stubbed (#16075 / peer SoundexJitHelper #26882 / StrRot13JitHelper #26868).
 * Thin AOT of the prior VmString-wordwrap delegate segfaulted after c:main_before_php.
 *
 * Logic mirrors VmString wordwrap() / php-src ext/standard/string.c PHP_FUNCTION(wordwrap).
 * NestedJIT: string concat only (no array mutation + implode); isset length; private helpers OK.
 *
 * Helper-runtime prelink must track NestedJIT string IR: a fingerprint-fresh unit.o can still
 * garble / abort under default `phpc build` (HELPER_RUNTIME_O unset/1) while HELPER_RUNTIME_O=0
 * NestedJIT matches Zend (#27217 peer class / #27237). Re-emit after string-lowering changes.
 */
final class WordwrapJitHelper
{
    public static function wordwrapArgv(string $text, int $width, string $break, int $cut): string
    {
        $cutBool = 0 !== $cut;
        $len = self::byteLength($text);
        if (0 === $len) {
            return '';
        }
        $breakLen = self::byteLength($break);
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

            return self::wordwrapGeneral($text, $len, $width, $break, $breakLen, true);
        }
        if (1 === $breakLen) {
            return self::wordwrapFastSingleByteBreak($text, $len, $width, $break[0]);
        }

        return self::wordwrapGeneral($text, $len, $width, $break, $breakLen, false);
    }

    /** Fast path: single-byte break, cut=false — rebuild via .= (NestedJIT-safe). */
    private static function wordwrapFastSingleByteBreak(string $text, int $len, int $width, string $breakByte): string
    {
        $laststart = 0;
        $lastspace = 0;
        $out = '';
        $segStart = 0;
        for ($current = 0; $current < $len; ++$current) {
            $ch = $text[$current];
            if ($ch === $breakByte) {
                $laststart = $current + 1;
                $lastspace = $current + 1;
            }
            if ($ch !== $breakByte && ' ' === $ch) {
                if ($current - $laststart >= $width) {
                    // Flush through prior char, emit break instead of this space.
                    $out .= self::byteSlice($text, $segStart, $current - $segStart);
                    $out .= $breakByte;
                    $segStart = $current + 1;
                    $laststart = $current + 1;
                }
                $lastspace = $current;
            }
            if ($ch !== $breakByte && ' ' !== $ch && $current - $laststart >= $width && $laststart !== $lastspace) {
                // Flush through lastspace-1, emit break at lastspace, resume after.
                $out .= self::byteSlice($text, $segStart, $lastspace - $segStart);
                $out .= $breakByte;
                $segStart = $lastspace + 1;
                $laststart = $lastspace + 1;
            }
        }
        if ($segStart < $len) {
            $out .= self::byteSlice($text, $segStart, $len - $segStart);
        }

        return $out;
    }

    /** General path: multi-byte break and cut=true (php-src php_wordwrap else branch). */
    private static function wordwrapGeneral(
        string $text,
        int $len,
        int $width,
        string $break,
        int $breakLen,
        bool $cut
    ): string {
        $out = '';
        $laststart = 0;
        $lastspace = 0;
        $current = 0;
        while ($current < $len) {
            if ($current + $breakLen <= $len
                && $text[$current] === $break[0]
                && 0 === self::byteCompareN($text, $current, $break, 0, $breakLen)) {
                $out .= self::byteSlice($text, $laststart, $current - $laststart + $breakLen);
                $current += $breakLen;
                $laststart = $current;
                $lastspace = $current;
            } elseif (' ' === $text[$current]) {
                if ($current - $laststart >= $width) {
                    $out .= self::byteSlice($text, $laststart, $current - $laststart);
                    $out .= $break;
                    $laststart = $current + 1;
                }
                $lastspace = $current;
                ++$current;
            } elseif ($cut && $current - $laststart >= $width && $laststart >= $lastspace) {
                $out .= self::byteSlice($text, $laststart, $current - $laststart);
                $out .= $break;
                $laststart = $lastspace = $current;
                ++$current;
            } elseif ($current - $laststart >= $width && $laststart < $lastspace) {
                $out .= self::byteSlice($text, $laststart, $lastspace - $laststart);
                $out .= $break;
                $laststart = $lastspace + 1;
                $lastspace = $laststart;
                ++$current;
            } else {
                ++$current;
            }
        }
        if ($laststart < $len) {
            $out .= self::byteSlice($text, $laststart, $len - $laststart);
        }

        return $out;
    }

    private static function byteLength(string $s): int
    {
        $n = 0;
        while (isset($s[$n])) {
            ++$n;
        }

        return $n;
    }

    private static function byteSlice(string $s, int $start, int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $out .= $s[$start + $i];
        }

        return $out;
    }

    private static function byteCompareN(string $a, int $aOff, string $b, int $bOff, int $n): int
    {
        for ($i = 0; $i < $n; ++$i) {
            if ($a[$aOff + $i] !== $b[$bOff + $i]) {
                return 1;
            }
        }

        return 0;
    }
}
