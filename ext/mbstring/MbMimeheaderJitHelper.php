<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\Base64JitHelper;

/**
 * mb_encode_mimeheader() / mb_decode_mimeheader() NestedJIT runtime (#34310 leftover of #34307/#6038).
 *
 * No {@see VmMbstring} TU (ExternalMethod stubs / SIGSEGV under thin AOT).
 * Encode: inline RFC 4648 range peel (no substr).
 * Decode: copy optional ASCII prefix; peel =?UTF-8?B?…?= via fixed `$word[0]` indices on a
 * copied word string (NestedJIT mis-reads `$str[$i+N]` / hangs on multi-pass scanners).
 * php-src: ext/mbstring/mbstring.c
 */
final class MbMimeheaderJitHelper
{
    private const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    private const PREFIX_LEN = 10;

    /**
     * @param string $charset Ignored at NestedJIT (caller gates UTF-8); ABI parity with #34307.
     * @param string $transferEncoding Leading B/b → Base64 peel; otherwise return $str unchanged.
     */
    public static function encodeArgv(string $str, string $charset, string $transferEncoding): string
    {
        unset($charset);
        if ('' === $str) {
            return '';
        }
        $base64 = true;
        if ('' !== $transferEncoding) {
            $flag = $transferEncoding[0];
            $base64 = 'B' === $flag || 'b' === $flag;
        }
        if (!$base64) {
            return $str;
        }

        $len = self::byteLen($str);
        $encodeStart = -1;
        $i = 0;
        while ($i < $len) {
            $ord = \ord($str[$i]);
            $safe = ($ord >= 0x20 && $ord <= 0x7E && 0x3D !== $ord && 0x3F !== $ord && 0x5F !== $ord);
            if (!$safe) {
                $encodeStart = $i;
                break;
            }
            $i = $i + 1;
        }
        if ($encodeStart < 0) {
            return $str;
        }

        $asciiEnd = 0;
        $encStart = 0;
        if ($encodeStart > 0) {
            $spacePos = -1;
            $j = $encodeStart - 1;
            while ($j >= 0) {
                if (' ' === $str[$j]) {
                    $spacePos = $j;
                    break;
                }
                $j = $j - 1;
            }
            if ($spacePos >= 0) {
                $asciiEnd = $spacePos + 1;
                $encStart = $spacePos + 1;
            }
        }

        $ascii = '';
        $k = 0;
        while ($k < $asciiEnd) {
            $ascii .= $str[$k];
            $k = $k + 1;
        }

        return $ascii.'=?UTF-8?B?'.self::b64EncodeRange($str, $encStart, $len - $encStart).'?=';
    }

    public static function decodeArgv(string $str): string
    {
        if ('' === $str) {
            return '';
        }
        $len = self::byteLen($str);
        if ($len < self::PREFIX_LEN + 3) {
            return $str;
        }

        // Fast path: whole string is one encoded-word (proven NestedJIT path).
        if (self::hasUtf8BPrefixAt0($str) && '?' === $str[$len - 2] && '=' === $str[$len - 1]) {
            return self::decodeFixedWord($str, $len);
        }

        // Mixed: ASCII + one =?UTF-8?B?…?= (encodeArgv shape). Find first '=' then copy word.
        $wordAt = -1;
        $i = 0;
        while ($i < $len) {
            if ('=' === $str[$i]) {
                $wordAt = $i;
                break;
            }
            $i = $i + 1;
        }
        if ($wordAt < 0) {
            return $str;
        }

        $ascii = '';
        $k = 0;
        while ($k < $wordAt) {
            $ascii .= $str[$k];
            $k = $k + 1;
        }

        $word = '';
        $r = $wordAt;
        while ($r < $len) {
            $word .= $str[$r];
            $r = $r + 1;
        }
        $wlen = self::byteLen($word);
        if ($wlen < self::PREFIX_LEN + 3
            || !self::hasUtf8BPrefixAt0($word)
            || '?' !== $word[$wlen - 2]
            || '=' !== $word[$wlen - 1]) {
            return $str;
        }

        return $ascii.self::decodeFixedWord($word, $wlen);
    }

    /** Fixed indices `$str[0]`… only — NestedJIT-safe (#34310). */
    private static function hasUtf8BPrefixAt0(string $str): bool
    {
        return '=' === $str[0]
            && '?' === $str[1]
            && 'U' === $str[2]
            && 'T' === $str[3]
            && 'F' === $str[4]
            && '-' === $str[5]
            && '8' === $str[6]
            && '?' === $str[7]
            && ('B' === $str[8] || 'b' === $str[8])
            && '?' === $str[9];
    }

    private static function decodeFixedWord(string $word, int $len): string
    {
        $dataStart = self::PREFIX_LEN;
        $dataEnd = $len - 2;
        $payload = '';
        $p = $dataStart;
        while ($p < $dataEnd) {
            $payload .= $word[$p];
            $p = $p + 1;
        }

        return Base64JitHelper::decodeArgv($payload);
    }

    private static function byteLen(string $s): int
    {
        $n = 0;
        while (isset($s[$n])) {
            $n = $n + 1;
            if ($n > 1048576) {
                break;
            }
        }

        return $n;
    }

    private static function b64EncodeRange(string $data, int $start, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        $alphabet = self::B64;
        $out = '';
        $i = 0;
        while ($i < $length) {
            $b0 = \ord($data[$start + $i]);
            $b1 = 0;
            $b2 = 0;
            if ($i + 1 < $length) {
                $b1 = \ord($data[$start + $i + 1]);
            }
            if ($i + 2 < $length) {
                $b2 = \ord($data[$start + $i + 2]);
            }
            $n = ($b0 << 16) | ($b1 << 8) | $b2;
            $out .= $alphabet[($n >> 18) & 63];
            $out .= $alphabet[($n >> 12) & 63];
            if ($i + 1 < $length) {
                $out .= $alphabet[($n >> 6) & 63];
            } else {
                $out .= '=';
            }
            if ($i + 2 < $length) {
                $out .= $alphabet[$n & 63];
            } else {
                $out .= '=';
            }
            $i = $i + 3;
        }

        return $out;
    }
}
