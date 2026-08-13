<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * soundex() NestedJIT/AOT SSOT (#30790).
 *
 * Peer {@see VmMetaphone}: NestedJIT-bundle with {@see SoundexJitHelper}.
 * Use strlen/substr + advanceIdx loops — not `$s[$i]`. Prefer `$i = $i + 1`
 * over `++$i` in the NestedJIT-hostile helper path. Avoid deep multi-arg
 * recursion (heap corruption that SIGSEGVs later builtins).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(soundex)
 */
final class VmSoundex
{
    private static function upperAt(string $word, int $len, int $index): string
    {
        if ($index < 0 || $index >= $len) {
            return '';
        }
        $ch = \substr($word, $index, 1);
        if ('' === $ch) {
            return '';
        }
        $ord = \ord($ch);
        if ($ord >= 97 && $ord <= 122) {
            return \chr($ord - 32);
        }

        return $ch;
    }

    /**
     * NestedJIT-safe index advance (#26815 / peer metaphone).
     * Use `$wIdx = $wIdx + 1` — `++$wIdx` in this helper has SIGSEGV'd under NestedJIT AOT.
     */
    private static function advanceIdx(int $wIdx, int $delta): int
    {
        $i = 0;
        while ($i < $delta) {
            $wIdx = $wIdx + 1;
            $i = $i + 1;
        }

        return $wIdx;
    }

    private static function soundexDigit(string $upper): string
    {
        switch ($upper) {
            case 'B':
            case 'F':
            case 'P':
            case 'V':
                return '1';
            case 'C':
            case 'G':
            case 'J':
            case 'K':
            case 'Q':
            case 'S':
            case 'X':
            case 'Z':
                return '2';
            case 'D':
            case 'T':
                return '3';
            case 'L':
                return '4';
            case 'M':
            case 'N':
                return '5';
            case 'R':
                return '6';
            default:
                return '0';
        }
    }

    public static function encode(string $word): string
    {
        $len = \strlen($word);
        $wIdx = 0;
        $out = '';
        $last = '0';
        while ($wIdx < $len) {
            $ch = self::upperAt($word, $len, $wIdx);
            $wIdx = self::advanceIdx($wIdx, 1);
            $ord = ('' === $ch) ? 0 : \ord($ch);
            if ($ord < 65 || $ord > 90) {
                continue;
            }
            $digit = self::soundexDigit($ch);
            if ('' === $out) {
                $out = $ch;
                $last = $digit;
                continue;
            }
            if ($digit !== $last) {
                if ('0' !== $digit && \strlen($out) < 4) {
                    $out = $out.$digit;
                }
                $last = $digit;
            }
        }
        if ('' === $out) {
            return '0000';
        }
        $olen = \strlen($out);
        if ($olen >= 4) {
            return \substr($out, 0, 4);
        }
        if (1 === $olen) {
            return $out.'000';
        }
        if (2 === $olen) {
            return $out.'00';
        }

        return $out.'0';
    }
}
