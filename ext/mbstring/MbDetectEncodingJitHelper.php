<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_detect_encoding() NestedJIT runtime (#34358 leftover of #3075).
 *
 * Order is a compile-time letter string: A=ASCII U=UTF-8 L=ISO-8859-1 B=8BIT.
 * Letter tests stay in detectArgv (NestedJIT === on helper params misfires).
 * Empty return means false. No VmMbstring / MbstringState.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_encoding)
 */
final class MbDetectEncodingJitHelper
{
    public static function detectArgv(string $string, string $orderCodes, int $strict): string
    {
        $utf8Ok = self::isValidUtf8($string);
        $asciiOk = self::isAscii($string);
        $hasUtf8 = false;
        $hasAscii = false;
        $firstAu = '';
        $n = self::byteLen($orderCodes);
        $i = 0;
        while ($i < $n) {
            $code = $orderCodes[$i];
            if ('A' === $code) {
                $hasAscii = true;
                if ('' === $firstAu) {
                    $firstAu = 'A';
                }
            } elseif ('U' === $code) {
                $hasUtf8 = true;
                if ('' === $firstAu) {
                    $firstAu = 'U';
                }
            }
            ++$i;
        }

        if ($hasUtf8 && $utf8Ok) {
            if (!$asciiOk) {
                return 'UTF-8';
            }
            if (!$hasAscii) {
                return 'UTF-8';
            }
            if ('U' === $firstAu) {
                return 'UTF-8';
            }
        }

        $i = 0;
        while ($i < $n) {
            $code = $orderCodes[$i];
            if ('A' === $code) {
                if ($asciiOk) {
                    return 'ASCII';
                }
            } elseif ('U' === $code) {
                // Zend defers UTF-8 to end when ASCII also matches.
            } elseif ('L' === $code) {
                return 'ISO-8859-1';
            } elseif ('B' === $code) {
                return '8BIT';
            }
            ++$i;
        }

        if ($hasUtf8 && $utf8Ok) {
            return 'UTF-8';
        }

        return '';
    }

    /** Char compare — NestedJIT ord()+int const misfires (#34338 peer MbScrubJitHelper). */
    private static function isAscii(string $string): bool
    {
        $len = self::byteLen($string);
        $i = 0;
        while ($i < $len) {
            if ($string[$i] > "\x7F") {
                return false;
            }
            ++$i;
        }

        return true;
    }

    private static function isValidUtf8(string $string): bool
    {
        $len = self::byteLen($string);
        if (0 === $len) {
            return true;
        }
        // Lone high bytes are never valid UTF-8; NestedJIT mis-lowers the general lead/continuation
        // walk for this shape under standalone AOT (#35315 leftover).
        if (1 === $len) {
            return $string[0] <= "\x7F";
        }
        $i = 0;
        while ($i < $len) {
            $ch = $string[$i];
            if ($ch <= "\x7F") {
                ++$i;

                continue;
            }
            // Lead-byte class via char range — NestedJIT ord()+mask misfires (#34358).
            if ($ch >= "\xC0" && $ch <= "\xDF") {
                $need = 1;
            } elseif ($ch >= "\xE0" && $ch <= "\xEF") {
                $need = 2;
            } elseif ($ch >= "\xF0" && $ch <= "\xF7") {
                $need = 3;
            } else {
                return false;
            }
            if ($i + $need >= $len) {
                return false;
            }
            $j = 1;
            while ($j <= $need) {
                $nextCh = $string[$i + $j];
                if ($nextCh < "\x80" || $nextCh > "\xBF") {
                    return false;
                }
                ++$j;
            }
            $i += $need + 1;
        }

        return true;
    }

    /** NestedJIT-safe length: strlen silent-0 (#34264). */
    private static function byteLen(string $s): int
    {
        $n = 0;
        while (isset($s[$n])) {
            ++$n;
        }

        return $n;
    }
}
