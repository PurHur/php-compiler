<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_detect_encoding() NestedJIT runtime (#34358 leftover of #3075).
 *
 * Leaf UTF-8 / ASCII / ISO-8859-1 / 8BIT detection — no VmMbstring / MbstringState
 * from thin AOT NestedJIT. Encoding order + strict are compile-time constants from
 * {@see JitMbDetectEncoding}; {@see detectArgv} returns null for false (peer
 * {@see MbSearchJitHelper} string|false).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_encoding)
 */
final class MbDetectEncodingJitHelper
{
    /**
     * @return string|null Encoding name, or null when detection fails (Zend false)
     */
    public static function detectArgv(string $value, string $orderCsv, bool $strict): ?string
    {
        $order = '' === $orderCsv ? [] : explode(',', $orderCsv);
        if ([] === $order) {
            return null;
        }

        if (\in_array('UTF-8', $order, true) && self::isValidUtf8($value)) {
            if (!self::isAsciiByteString($value)) {
                return 'UTF-8';
            }
            $utf8Pos = \array_search('UTF-8', $order, true);
            $asciiPos = \array_search('ASCII', $order, true);
            if (false === $asciiPos || (false !== $utf8Pos && $utf8Pos < $asciiPos)) {
                return 'UTF-8';
            }
        }

        foreach ($order as $encoding) {
            if ('UTF-8' === $encoding) {
                continue;
            }
            if (self::stringMatchesEncoding($value, $encoding, $strict)) {
                return $encoding;
            }
        }

        if (\in_array('UTF-8', $order, true) && self::isValidUtf8($value)) {
            return 'UTF-8';
        }

        return null;
    }

    private static function stringMatchesEncoding(string $string, string $encoding, bool $strict): bool
    {
        if ('UTF-8' === $encoding) {
            return self::isValidUtf8($string);
        }
        if ('ASCII' === $encoding) {
            return self::isAsciiByteString($string);
        }
        if ('ISO-8859-1' === $encoding || '8BIT' === $encoding) {
            if (!$strict) {
                return true;
            }

            return self::strictLatin1ByteString($string);
        }

        return false;
    }

    private static function isAsciiByteString(string $string): bool
    {
        $len = \strlen($string);
        for ($i = 0; $i < $len; ++$i) {
            if (\ord($string[$i]) >= 0x80) {
                return false;
            }
        }

        return true;
    }

    private static function strictLatin1ByteString(string $string): bool
    {
        $len = \strlen($string);
        for ($i = 0; $i < $len; ++$i) {
            $byte = \ord($string[$i]);
            if ($byte <= 0x7F) {
                continue;
            }
            $expect = self::latin1ByteToUtf8($byte);
            if ($i + \strlen($expect) > $len || \substr($string, $i, \strlen($expect)) !== $expect) {
                return false;
            }
            $i += \strlen($expect) - 1;
        }

        return true;
    }

    private static function latin1ByteToUtf8(int $byte): string
    {
        if ($byte <= 0x7F) {
            return \chr($byte);
        }

        return \chr(0xC0 | ($byte >> 6)).\chr(0x80 | ($byte & 0x3F));
    }

    private static function isValidUtf8(string $value): bool
    {
        $len = \strlen($value);
        for ($i = 0; $i < $len; ) {
            $byte = \ord($value[$i]);
            if ($byte < 0x80) {
                ++$i;
                continue;
            }
            $need = 0;
            if (!self::utf8SequenceValidAt($value, $len, $i, $need)) {
                return false;
            }
            $i += $need + 1;
        }

        return true;
    }

    /**
     * @param-out int $need
     */
    private static function utf8SequenceValidAt(string $value, int $len, int $i, ?int &$need = null): bool
    {
        $byte = \ord($value[$i]);
        if ($byte < 0x80) {
            $need = 0;

            return true;
        }
        if (($byte & 0xE0) === 0xC0) {
            $need = 1;
            $min = 0x80;
        } elseif (($byte & 0xF0) === 0xE0) {
            $need = 2;
            $min = 0x800;
        } elseif (($byte & 0xF8) === 0xF0) {
            $need = 3;
            $min = 0x10000;
        } else {
            $need = 0;

            return false;
        }
        if ($i + $need >= $len) {
            return false;
        }
        $cp = $byte & (0xFF >> (2 + $need));
        for ($j = 1; $j <= $need; ++$j) {
            $next = \ord($value[$i + $j]);
            if (($next & 0xC0) !== 0x80) {
                return false;
            }
            $cp = ($cp << 6) | ($next & 0x3F);
        }
        if ($cp < $min || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
            return false;
        }

        return true;
    }
}
