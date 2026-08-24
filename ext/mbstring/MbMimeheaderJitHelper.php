<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_encode_mimeheader() / mb_decode_mimeheader() NestedJIT runtime (#34299 leftover of #6038).
 *
 * NestedJIT-safe peel (peer {@see MbStrcutJitHelper}):
 * - No VmMbstring call (encode→decode in one AOT binary SIGSEGVs — #34310 / re-#34299).
 * - No assoc arrays / foreach / preg / native base64_* / sprintf / computed `$data[$i+1]`.
 * - Byte length via isset; base64 encode via sequential `$i++` walk; decode via Base64JitHelper.
 *
 * SSOT for VM / compile-time fold: {@see MimeHeaderConvert}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_encode_mimeheader) / mb_decode_mimeheader
 *
 * Call ABI matches {@see JitMbMimeheader::invokeEncode} (3 string args; #34307).
 */
final class MbMimeheaderJitHelper
{
    /**
     * $transferEncoding first byte: B/b → Base64; anything else (incl. Q) → Quoted-Printable.
     * Empty string matches omitted/null transfer_encoding → Base64 (php-src default).
     */
    public static function encodeArgv(
        string $str,
        string $charset,
        string $transferEncoding
    ): string {
        $len = 0;
        while (isset($str[$len])) {
            ++$len;
        }
        if (0 === $len) {
            return '';
        }
        if ('UTF-8' !== $charset && 'ASCII' !== $charset && '8BIT' !== $charset) {
            return '';
        }
        $base64 = true;
        if ('' !== $transferEncoding) {
            $flag = $transferEncoding[0];
            $base64 = 'B' === $flag || 'b' === $flag;
        }
        if (self::canPassThrough($str, $len)) {
            return $str;
        }

        $encodeStart = self::firstUnsafeIndex($str, $len);
        $ascii = '';
        $encoded = $str;
        if ($encodeStart > 0) {
            $spacePos = -1;
            $j = $encodeStart - 1;
            while ($j >= 0) {
                if (' ' === $str[$j]) {
                    $spacePos = $j;
                    break;
                }
                --$j;
            }
            if ($spacePos >= 0) {
                $ascii = \substr($str, 0, $spacePos + 1);
                $encoded = \substr($str, $spacePos + 1);
            }
        }

        $mimeCharset = 'ASCII' === $charset || '8BIT' === $charset ? 'ISO-8859-1' : $charset;
        $word = $base64
            ? '=?'.$mimeCharset.'?B?'.self::b64Encode($encoded).'?='
            : '=?'.$mimeCharset.'?Q?'.self::qEncode($encoded).'?=';

        return $ascii.$word;
    }

    public static function decodeArgv(string $str): string
    {
        $len = 0;
        while (isset($str[$len])) {
            ++$len;
        }
        if (0 === $len) {
            return '';
        }

        $out = '';
        $i = 0;
        while ($i < $len) {
            if ('=' === $str[$i] && ($i + 1) < $len && '?' === $str[$i + 1]) {
                $word = self::decodeWordAt($str, $i, $len);
                if (null !== $word) {
                    $out .= $word[0];
                    $i = $word[1];
                    while ($i < $len && self::isWhitespace($str[$i])) {
                        ++$i;
                    }
                    if ($i < $len && '=' === $str[$i] && ($i + 1) < $len && '?' === $str[$i + 1]) {
                        continue;
                    }
                    if ($i < $len) {
                        $out .= ' ';
                    }
                    continue;
                }
            }

            $start = $i;
            while ($i < $len) {
                if ('=' === $str[$i] && ($i + 1) < $len && '?' === $str[$i + 1]) {
                    break;
                }
                if ("\n" === $str[$i] || "\r" === $str[$i]) {
                    ++$i;
                    while ($i < $len && self::isWhitespace($str[$i])) {
                        ++$i;
                    }
                    if ($i < $len) {
                        $out .= ' ';
                    }
                    break;
                }
                ++$i;
            }
            if ($i > $start) {
                $out .= \substr($str, $start, $i - $start);
            }
        }

        return $out;
    }

    private static function canPassThrough(string $str, int $len): bool
    {
        $checkingLeading = 1;
        for ($i = 0; $i < $len; ++$i) {
            $byte = $str[$i];
            if (1 === $checkingLeading && ' ' === $byte) {
                continue;
            }
            $checkingLeading = 0;
            if (!self::isGraphSafe($byte)) {
                return false;
            }
        }

        return true;
    }

    private static function firstUnsafeIndex(string $str, int $len): int
    {
        for ($i = 0; $i < $len; ++$i) {
            if (!self::isSafeAsciiByte($str[$i])) {
                return $i;
            }
        }

        return $len;
    }

    /** php-src safe ASCII for mime header pass-through (space allowed). */
    private static function isSafeAsciiByte(string $byte): bool
    {
        return $byte >= ' ' && $byte <= '~' && '=' !== $byte && '?' !== $byte && '_' !== $byte;
    }

    /** Pass-through after leading spaces rejects space itself (php-src mbfl). */
    private static function isGraphSafe(string $byte): bool
    {
        return $byte >= '!' && $byte <= '~' && '=' !== $byte && '?' !== $byte && '_' !== $byte;
    }

    private static function isWhitespace(string $byte): bool
    {
        return ' ' === $byte || "\t" === $byte || "\r" === $byte || "\n" === $byte;
    }

    private static function qEncode(string $text): string
    {
        $out = '';
        $len = 0;
        while (isset($text[$len])) {
            ++$len;
        }
        $digits = '0123456789ABCDEF';
        for ($i = 0; $i < $len; ++$i) {
            $byte = $text[$i];
            if (self::isGraphSafe($byte)) {
                $out .= $byte;
                continue;
            }
            if (' ' === $byte) {
                $out .= '_';
                continue;
            }
            $ord = self::byteOrd($byte);
            $out .= '='.$digits[($ord >> 4) & 15].$digits[$ord & 15];
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function decodeWordAt(string $str, int $pos, int $len)
    {
        if (($pos + 5) >= $len || '=' !== $str[$pos] || '?' !== $str[$pos + 1]) {
            return null;
        }
        $charsetEnd = \strpos($str, '?', $pos + 2);
        if (false === $charsetEnd || ($charsetEnd + 2) >= $len) {
            return null;
        }
        $encoding = $str[$charsetEnd + 1];
        if ('?' !== $str[$charsetEnd + 2]) {
            return null;
        }
        $dataStart = $charsetEnd + 3;
        $dataEnd = \strpos($str, '?=', $dataStart);
        if (false === $dataEnd) {
            if ($len > $dataStart && '?' === $str[$len - 1]) {
                $dataEnd = $len - 1;
                $next = $len;
            } else {
                return null;
            }
        } else {
            $next = $dataEnd + 2;
        }
        $payload = \substr($str, $dataStart, $dataEnd - $dataStart);
        if ('Q' === $encoding || 'q' === $encoding) {
            $decoded = self::qDecode($payload);
        } else {
            $decoded = self::b64Decode($payload);
        }

        return [$decoded, $next];
    }

    /**
     * NestedJIT-safe base64 encode — walk bytes with `$i++` only (not `$data[$i+1]`).
     * Peer Base64JitHelper::encodeArgv mis-reads continuation bytes via computed indexes under NestedJIT.
     */
    private static function b64Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        $len = 0;
        while (isset($data[$len])) {
            ++$len;
        }
        if (0 === $len) {
            return '';
        }
        $out = '';
        $i = 0;
        while ($i < $len) {
            $b0 = self::byteOrd($data[$i]);
            ++$i;
            $have1 = 0;
            $have2 = 0;
            $b1 = 0;
            $b2 = 0;
            if ($i < $len) {
                $b1 = self::byteOrd($data[$i]);
                ++$i;
                $have1 = 1;
            }
            if ($i < $len) {
                $b2 = self::byteOrd($data[$i]);
                ++$i;
                $have2 = 1;
            }
            // Prefer multiply/intdiv over large shifts (NestedJIT bit-accumulator bugs — #26890).
            $n = ($b0 * 65536) + ($b1 * 256) + $b2;
            $out .= $alphabet[intdiv($n, 262144) % 64];
            $out .= $alphabet[intdiv($n, 4096) % 64];
            if (1 === $have1) {
                $out .= $alphabet[intdiv($n, 64) % 64];
            } else {
                $out .= '=';
            }
            if (1 === $have2) {
                $out .= $alphabet[$n % 64];
            } else {
                $out .= '=';
            }
        }

        return $out;
    }

    private static function b64Decode(string $payload): string
    {
        $clean = '';
        $len = 0;
        while (isset($payload[$len])) {
            ++$len;
        }
        for ($i = 0; $i < $len; ++$i) {
            $ch = $payload[$i];
            if ("\r" === $ch || "\n" === $ch || "\t" === $ch || ' ' === $ch || '=' === $ch) {
                continue;
            }
            $clean .= $ch;
        }
        if ('' === $clean) {
            return '';
        }

        // Decode uses i%4 / intdiv — NestedJIT-safe (Base64JitHelper::decodeArgv / #26890).
        return \PHPCompiler\ext\standard\Base64JitHelper::decodeArgv($clean);
    }

    private static function qDecode(string $payload): string
    {
        $out = '';
        $len = 0;
        while (isset($payload[$len])) {
            ++$len;
        }
        for ($i = 0; $i < $len; ++$i) {
            $byte = $payload[$i];
            if ('_' === $byte) {
                $out .= ' ';
                continue;
            }
            if ('=' === $byte && ($i + 2) < $len) {
                $hi = self::hexNibble($payload[$i + 1]);
                $lo = self::hexNibble($payload[$i + 2]);
                if ($hi >= 0 && $lo >= 0) {
                    $out .= self::byteAt(($hi * 16) + $lo);
                    $i += 2;
                    continue;
                }
            }
            $out .= $byte;
        }

        return $out;
    }

    private static function hexNibble(string $ch): int
    {
        return match ($ch) {
            '0' => 0,
            '1' => 1,
            '2' => 2,
            '3' => 3,
            '4' => 4,
            '5' => 5,
            '6' => 6,
            '7' => 7,
            '8' => 8,
            '9' => 9,
            'A', 'a' => 10,
            'B', 'b' => 11,
            'C', 'c' => 12,
            'D', 'd' => 13,
            'E', 'e' => 14,
            'F', 'f' => 15,
            default => -1,
        };
    }

    /** NestedJIT-safe byte ordinal (peer Base64JitHelper::byteOrd). */
    private static function byteOrd(string $byte): int
    {
        $all = self::allBytes();
        for ($code = 0; $code < 256; ++$code) {
            if ($byte === $all[$code]) {
                return $code;
            }
        }

        return 0;
    }

    private static function byteAt(int $code): string
    {
        if ($code < 0 || $code > 255) {
            return "\0";
        }

        return self::allBytes()[$code];
    }

    private static function allBytes(): string
    {
        return "\0\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
            ."\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f"
            .' !"#$%&\'()*+,-./0123456789:;<=>?'
            .'@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_'
            .'`abcdefghijklmnopqrstuvwxyz{|}~'."\x7f"
            ."\x80\x81\x82\x83\x84\x85\x86\x87\x88\x89\x8a\x8b\x8c\x8d\x8e\x8f"
            ."\x90\x91\x92\x93\x94\x95\x96\x97\x98\x99\x9a\x9b\x9c\x9d\x9e\x9f"
            ."\xa0\xa1\xa2\xa3\xa4\xa5\xa6\xa7\xa8\xa9\xaa\xab\xac\xad\xae\xaf"
            ."\xb0\xb1\xb2\xb3\xb4\xb5\xb6\xb7\xb8\xb9\xba\xbb\xbc\xbd\xbe\xbf"
            ."\xc0\xc1\xc2\xc3\xc4\xc5\xc6\xc7\xc8\xc9\xca\xcb\xcc\xcd\xce\xcf"
            ."\xd0\xd1\xd2\xd3\xd4\xd5\xd6\xd7\xd8\xd9\xda\xdb\xdc\xdd\xde\xdf"
            ."\xe0\xe1\xe2\xe3\xe4\xe5\xe6\xe7\xe8\xe9\xea\xeb\xec\xed\xee\xef"
            ."\xf0\xf1\xf2\xf3\xf4\xf5\xf6\xf7\xf8\xf9\xfa\xfb\xfc\xfd\xfe\xff";
    }
}
