<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * UTF-8 helpers for VmPregPure nested JIT — no VM frame deps (#16075).
 *
 * Subset of {@see VmString} utf8 paths; avoids compiling full VmString.php in AOT preg bundle.
 */
final class VmPregUtf8
{
    public static function byteLength(string $string): int
    {
        return \strlen($string);
    }

    public static function utf8CharLength(string $string): int
    {
        $byteLen = self::byteLength($string);
        $count = 0;
        for ($i = 0; $i < $byteLen; ++$count) {
            $byte = \ord($string[$i]);
            if ($byte < 0x80) {
                $i += 1;
            } elseif (($byte & 0xE0) === 0xC0 && $i + 1 < $byteLen) {
                $i += 2;
            } elseif (($byte & 0xF0) === 0xE0 && $i + 2 < $byteLen) {
                $i += 3;
            } elseif (($byte & 0xF8) === 0xF0 && $i + 3 < $byteLen) {
                $i += 4;
            } else {
                $i += 1;
            }
        }

        return $count;
    }

    public static function isValidUtf8(string $string): bool
    {
        $len = self::byteLength($string);
        for ($i = 0; $i < $len; ) {
            if (!self::utf8SequenceValidAt($string, $len, $i, $need)) {
                return false;
            }
            $i += $need + 1;
        }

        return true;
    }

    public static function utf8CharSubstr(string $string, int $charOffset, int $charCount): string
    {
        if ($charCount <= 0) {
            return '';
        }
        $byteLen = self::byteLength($string);
        $bytePos = 0;
        for ($skipped = 0; $skipped < $charOffset && $bytePos < $byteLen; ++$skipped) {
            $bytePos += self::utf8CharByteWidth($string, $bytePos);
        }
        $start = $bytePos;
        for ($taken = 0; $taken < $charCount && $bytePos < $byteLen; ++$taken) {
            $bytePos += self::utf8CharByteWidth($string, $bytePos);
        }

        return \substr($string, $start, $bytePos - $start);
    }

    public static function utf8CharByteWidth(string $string, int $bytePos): int
    {
        $byteLen = self::byteLength($string);
        if ($bytePos >= $byteLen) {
            return 0;
        }
        $byte = \ord($string[$bytePos]);
        if ($byte < 0x80) {
            return 1;
        }
        if (($byte & 0xE0) === 0xC0 && $bytePos + 1 < $byteLen) {
            return 2;
        }
        if (($byte & 0xF0) === 0xE0 && $bytePos + 2 < $byteLen) {
            return 3;
        }
        if (($byte & 0xF8) === 0xF0 && $bytePos + 3 < $byteLen) {
            return 4;
        }

        return 1;
    }

    /**
     * @param-out int $need continuation byte count when lead byte is multi-byte
     */
    private static function utf8SequenceValidAt(string $string, int $len, int $i, ?int &$need = null): bool
    {
        $byte = \ord($string[$i]);
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
            $next = \ord($string[$i + $j]);
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
