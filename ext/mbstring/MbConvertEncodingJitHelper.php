<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_convert_encoding() NestedJIT runtime (#34309 leftover of #6251).
 *
 * Minimal UTF-8 / ISO-8859-1 / ASCII leaf — avoids NestedJIT of full CharsetEngine
 * (SEGV at c:main_before_php). From-encoding is always passed explicitly (internal
 * encoding resolved at compile time — NestedJIT MbstringState aborts).
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_encoding)
 */
final class MbConvertEncodingJitHelper
{
    public static function convertArgv(string $string, string $toEncoding, string $fromEncoding): string
    {
        $from = self::canon($fromEncoding);
        $to = self::canon($toEncoding);
        if ($from === $to) {
            return $string;
        }
        if ('UTF-8' === $from && 'ISO-8859-1' === $to) {
            return self::utf8ToLatin1($string);
        }
        if ('ISO-8859-1' === $from && 'UTF-8' === $to) {
            return self::latin1ToUtf8($string);
        }
        if ('UTF-8' === $from && 'ASCII' === $to) {
            return self::utf8ToAscii($string);
        }
        if ('ASCII' === $from && 'UTF-8' === $to) {
            return $string;
        }

        return '';
    }

    private static function canon(string $encoding): string
    {
        $e = strtoupper($encoding);
        if ('UTF8' === $e || 'UTF-8' === $e) {
            return 'UTF-8';
        }
        if ('LATIN1' === $e || 'LATIN-1' === $e || 'ISO-8859-1' === $e) {
            return 'ISO-8859-1';
        }
        if ('ASCII' === $e || 'US-ASCII' === $e) {
            return 'ASCII';
        }

        return $e;
    }

    private static function latin1ToUtf8(string $input): string
    {
        $out = '';
        $len = \strlen($input);
        for ($i = 0; $i < $len; ++$i) {
            $byte = \ord($input[$i]);
            if ($byte < 0x80) {
                $out .= $input[$i];
            } else {
                $out .= \chr(0xC0 | ($byte >> 6)).\chr(0x80 | ($byte & 0x3F));
            }
        }

        return $out;
    }

    private static function utf8ToLatin1(string $input): string
    {
        $out = '';
        $len = \strlen($input);
        $i = 0;
        while ($i < $len) {
            $c = \ord($input[$i]);
            if ($c < 0x80) {
                $out .= $input[$i];
                ++$i;
            } elseif ($c < 0xC0) {
                ++$i;
            } elseif ($c < 0xE0 && $i + 1 < $len) {
                $c2 = \ord($input[$i + 1]);
                $cp = (($c & 0x1F) << 6) | ($c2 & 0x3F);
                $out .= $cp <= 0xFF ? \chr($cp) : '?';
                $i += 2;
            } else {
                $out .= '?';
                if ($c < 0xF0) {
                    $i += 3;
                } elseif ($c < 0xF8) {
                    $i += 4;
                } else {
                    ++$i;
                }
            }
        }

        return $out;
    }

    private static function utf8ToAscii(string $input): string
    {
        $out = '';
        $len = \strlen($input);
        $i = 0;
        while ($i < $len) {
            $c = \ord($input[$i]);
            if ($c < 0x80) {
                $out .= $input[$i];
                ++$i;
            } elseif ($c < 0xE0) {
                $out .= '?';
                $i += 2;
            } elseif ($c < 0xF0) {
                $out .= '?';
                $i += 3;
            } else {
                $out .= '?';
                $i += 4;
            }
        }

        return $out;
    }
}
