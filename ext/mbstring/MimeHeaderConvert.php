<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * RFC 2047 MIME header encode/decode core (php-src ext/mbstring/mbstring.c; #6038 / #34299).
 *
 * Extracted from {@see VmMbstring} so NestedJIT can lower without pulling the full VmMbstring TU
 * (peer {@see KanaConvert} / #34294 — NestedJIT of VmMbstring::encode+decode in one binary SIGSEGVs).
 */
final class MimeHeaderConvert
{
    public static function encode(
        string $str,
        string $charset = 'UTF-8',
        bool $base64 = true,
        string $linefeed = "\r\n",
        int $indent = 0
    ): string {
        unset($linefeed); // php-src folds long lines; indent reserved for parity with VmMbstring.
        if ('' === $str) {
            return '';
        }
        self::assertCharset($charset);
        if ($indent < 0 || $indent >= 74) {
            $indent = 0;
        }
        unset($indent);
        if (self::canPassThrough($str)) {
            return $str;
        }

        $parts = self::splitSegments($str);
        $out = '';
        $n = \count($parts);
        for ($p = 0; $p < $n; ++$p) {
            $part = $parts[$p];
            if ('ascii' === $part['type']) {
                $out .= $part['text'];
                continue;
            }
            if ('' !== $out && !self::endsWithSpace($out)) {
                $out .= ' ';
            }
            $out .= self::encodeWord($part['text'], $charset, $base64);
        }

        return $out;
    }

    public static function decode(string $str): string
    {
        if ('' === $str) {
            return '';
        }

        $len = \strlen($str);
        $out = '';
        $i = 0;
        while ($i < $len) {
            if ('=' === $str[$i] && ($i + 1) < $len && '?' === $str[$i + 1]) {
                $decoded = self::decodeWordAt($str, $i, $len);
                if (null !== $decoded) {
                    $out .= $decoded[0];
                    $i = $decoded[1];
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

    private static function assertCharset(string $charset): void
    {
        if ('UTF-8' !== $charset && 'ASCII' !== $charset && '8BIT' !== $charset) {
            throw new \ValueError(\sprintf(
                'mb_encode_mimeheader(): Argument #2 ($charset) is not a supported encoding, "%s" given',
                $charset
            ));
        }
    }

    private static function canPassThrough(string $str): bool
    {
        $checkingLeading = true;
        $len = \strlen($str);
        for ($i = 0; $i < $len; ++$i) {
            $byte = \ord($str[$i]);
            if ($checkingLeading && 0x20 === $byte) {
                continue;
            }
            $checkingLeading = false;
            if ($byte < 0x21 || $byte > 0x7E || 0x3D === $byte || 0x3F === $byte || 0x5F === $byte) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{type: 'ascii'|'encoded', text: string}>
     */
    private static function splitSegments(string $str): array
    {
        $len = \strlen($str);
        $encodeStart = null;
        for ($i = 0; $i < $len; ++$i) {
            if (!self::isSafeAsciiByte($str[$i])) {
                $encodeStart = $i;
                break;
            }
        }
        if (null === $encodeStart) {
            return [['type' => 'ascii', 'text' => $str]];
        }
        if (0 === $encodeStart) {
            return [['type' => 'encoded', 'text' => $str]];
        }
        $spacePos = null;
        for ($j = $encodeStart - 1; $j >= 0; --$j) {
            if (' ' === $str[$j]) {
                $spacePos = $j;
                break;
            }
        }
        if (null === $spacePos) {
            return [['type' => 'encoded', 'text' => $str]];
        }

        return [
            ['type' => 'ascii', 'text' => \substr($str, 0, $spacePos + 1)],
            ['type' => 'encoded', 'text' => \substr($str, $spacePos + 1)],
        ];
    }

    private static function isSafeAsciiByte(string $byte): bool
    {
        $ord = \ord($byte);

        return $ord >= 0x20 && $ord <= 0x7E && 0x3D !== $ord && 0x3F !== $ord && 0x5F !== $ord;
    }

    private static function isWhitespace(string $byte): bool
    {
        return ' ' === $byte || "\t" === $byte || "\r" === $byte || "\n" === $byte;
    }

    private static function endsWithSpace(string $out): bool
    {
        $len = \strlen($out);

        return $len > 0 && ' ' === $out[$len - 1];
    }

    private static function encodeWord(string $text, string $charset, bool $base64): string
    {
        $mimeCharset = 'ASCII' === $charset || '8BIT' === $charset ? 'ISO-8859-1' : $charset;

        return $base64
            ? '=?'.$mimeCharset.'?B?'.\PHPCompiler\ext\standard\Base64JitHelper::encodeArgv($text).'?='
            : '=?'.$mimeCharset.'?Q?'.self::qEncode($text).'?=';
    }

    private static function qEncode(string $text): string
    {
        $out = '';
        $len = \strlen($text);
        for ($i = 0; $i < $len; ++$i) {
            $byte = $text[$i];
            $ord = \ord($byte);
            if ($ord >= 0x20 && $ord <= 0x7E && 0x3D !== $ord && 0x3F !== $ord && 0x5F !== $ord) {
                $out .= $byte;
                continue;
            }
            if (0x20 === $ord) {
                $out .= '_';
                continue;
            }
            $out .= '='.self::hexUpperByte($ord);
        }

        return $out;
    }

    private static function hexUpperByte(int $ord): string
    {
        $hi = ($ord >> 4) & 15;
        $lo = $ord & 15;
        $digits = '0123456789ABCDEF';

        return $digits[$hi].$digits[$lo];
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function decodeWordAt(string $str, int $pos, int $len): ?array
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
        $decoded = ('Q' === $encoding || 'q' === $encoding)
            ? self::qDecode($payload)
            : self::base64Decode($payload);

        return [$decoded, $next];
    }

    private static function base64Decode(string $payload): string
    {
        // NestedJIT-safe whitespace/= strip (no preg_replace) then Base64JitHelper (#26890).
        $clean = '';
        $len = \strlen($payload);
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

        return \PHPCompiler\ext\standard\Base64JitHelper::decodeArgv($clean);
    }

    private static function qDecode(string $payload): string
    {
        $out = '';
        $len = \strlen($payload);
        for ($i = 0; $i < $len; ++$i) {
            $byte = $payload[$i];
            if ('_' === $byte) {
                $out .= ' ';
                continue;
            }
            if ('=' === $byte && ($i + 2) < $len) {
                $hex = \hexdec(\substr($payload, $i + 1, 2));
                $out .= \chr((int) $hex);
                $i += 2;
                continue;
            }
            $out .= $byte;
        }

        return $out;
    }
}
