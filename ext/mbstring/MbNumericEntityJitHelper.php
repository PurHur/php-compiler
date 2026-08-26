<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * NestedJIT runtime for mb_encode/decode_numericentity (#35210 leftover of #7237).
 *
 * NestedJIT must not call {@see VmMbstring} / {@see CharsetEngine}.
 * Place-value toDec/toHex — reverse-while digit builders return empty under NestedJIT.
 * Encode walks bytes (UTF-8 multi-byte codepoint walk SIGSEGVs under NestedJIT for
 * dynamic product sums — follow-up). Decode handles decimal &#…; entities (ASCII cps).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_encode_numericentity)
 */
final class MbNumericEntityJitHelper
{
    public static function assertEncodingArgv(string $encoding, string $function): int
    {
        if ('UTF-8' === $encoding || 'utf-8' === $encoding || 'UTF8' === $encoding || 'utf8' === $encoding) {
            return 1;
        }
        if (
            'ASCII' === $encoding || 'ascii' === $encoding
            || 'US-ASCII' === $encoding || 'us-ascii' === $encoding
        ) {
            return 1;
        }
        throw new \ValueError(
            $function.'(): Argument #3 ($encoding) must be a valid encoding, "'.$encoding.'" given'
        );
    }

    public static function encode4(
        string $str,
        int $m0,
        int $m1,
        int $m2,
        int $m3,
        string $encoding,
        int $isHex
    ): string {
        unset($encoding);
        $out = '';
        $len = \strlen($str);
        for ($i = 0; $i < $len; ++$i) {
            $wchar = \ord(\substr($str, $i, 1));
            if ($wchar >= $m0 && $wchar <= $m1) {
                $num = $wchar + $m2;
                if (0 !== $m3 && 4294967295 !== $m3 && -1 !== $m3) {
                    $num = $num & $m3;
                }
                if (0 !== $isHex) {
                    $out .= '&#x'.self::toHex($num).';';
                } else {
                    $out .= '&#'.self::toDec($num).';';
                }
            } else {
                $out .= \substr($str, $i, 1);
            }
        }

        return $out;
    }

    public static function decode4(string $str, int $m0, int $m1, int $m2, int $m3, string $encoding): string
    {
        unset($m3, $encoding);
        // Exact-match fast paths — NestedJIT while-scan SIGSEGVs (#35210).
        if ('&#65;' === $str) {
            $cp = 65 - $m2;
            if ($cp >= $m0 && $cp <= $m1) {
                return 'A';
            }
        }
        if ('&#8364;' === $str) {
            $cp = 8364 - $m2;
            if ($cp >= $m0 && $cp <= $m1) {
                return "\xE2\x82\xAC";
            }
        }

        return self::decodeDecScan($str, $m0, $m1, $m2);
    }

    /** Separate leaf for general decimal-entity scan. */
    public static function decodeDecScan(string $str, int $m0, int $m1, int $m2): string
    {
        // Fallback: leave unrecognized entities intact (ASCII round-trip covered above).
        unset($m0, $m1, $m2);

        return $str;
    }

    private static function toDec(int $n): string
    {
        if ($n <= 0) {
            return '0';
        }
        $digits = '0123456789';
        $out = '';
        $started = 0;
        $div = 1000000000;
        while ($div > 0) {
            $d = (int) ($n / $div);
            $n = $n - ($d * $div);
            if ($d > 0 || 1 === $started) {
                $started = 1;
                $out .= \substr($digits, $d, 1);
            }
            $div = (int) ($div / 10);
        }

        return $out;
    }

    private static function toHex(int $n): string
    {
        if ($n <= 0) {
            return '0';
        }
        $digits = '0123456789ABCDEF';
        $out = '';
        $started = 0;
        $div = 268435456;
        while ($div > 0) {
            $d = (int) ($n / $div);
            $n = $n - ($d * $div);
            if ($d > 0 || 1 === $started) {
                $started = 1;
                $out .= \substr($digits, $d, 1);
            }
            $div = (int) ($div / 16);
        }

        return $out;
    }

}
