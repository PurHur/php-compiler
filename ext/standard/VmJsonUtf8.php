<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * UTF-8 validation for JSON string literals (Zend ext/json scanner subset).
 */
final class VmJsonUtf8
{
    /**
     * Validate decoded bytes of a JSON string literal body (between quotes, escapes intact).
     */
    public static function isValidJsonStringContent(string $content): bool
    {
        return self::isValidUtf8(self::decodeJsonStringContent($content));
    }

    /**
     * @return string decoded UTF-8 bytes
     */
    public static function decodeJsonStringContent(string $content): string
    {
        $len = \strlen($content);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $c = $content[$i];
            if ('\\' !== $c || $i + 1 >= $len) {
                $out .= $c;
                continue;
            }
            $esc = $content[++$i];
            if ('u' === $esc) {
                if ($i + 4 >= $len) {
                    return $out;
                }
                $hex = \substr($content, $i + 1, 4);
                if (4 !== \strspn($hex, '0123456789abcdefABCDEF')) {
                    return $out;
                }
                $out .= self::encodeCodepoint((int) \hexdec($hex));
                $i += 4;
                continue;
            }
            $out .= match ($esc) {
                '"', '\\', '/' => $esc,
                'b' => "\x08",
                'f' => "\x0C",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                default => '\\'.$esc,
            };
        }

        return $out;
    }

    public static function isValidUtf8(string $bytes): bool
    {
        $len = \strlen($bytes);
        for ($i = 0; $i < $len;) {
            $c = \ord($bytes[$i]);
            if ($c <= 0x7F) {
                $i++;
                continue;
            }
            if ($c < 0xC2 || $c > 0xF4) {
                return false;
            }
            $extra = match (true) {
                $c <= 0xDF => 1,
                $c <= 0xEF => 2,
                default => 3,
            };
            if ($i + $extra >= $len) {
                return false;
            }
            for ($j = 1; $j <= $extra; $j++) {
                $next = \ord($bytes[$i + $j]);
                if ($next < 0x80 || $next > 0xBF) {
                    return false;
                }
            }
            $i += 1 + $extra;
        }

        return true;
    }

    private static function encodeCodepoint(int $cp): string
    {
        if ($cp <= 0x7F) {
            return \chr($cp);
        }
        if ($cp <= 0x7FF) {
            return \chr(0xC0 | ($cp >> 6)).\chr(0x80 | ($cp & 0x3F));
        }
        if ($cp <= 0xFFFF) {
            return \chr(0xE0 | ($cp >> 12)).\chr(0x80 | (($cp >> 6) & 0x3F)).\chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18)).\chr(0x80 | (($cp >> 12) & 0x3F)).\chr(0x80 | (($cp >> 6) & 0x3F)).\chr(0x80 | ($cp & 0x3F));
    }
}
