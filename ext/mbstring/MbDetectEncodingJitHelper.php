<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_detect_encoding() NestedJIT runtime (#34358 leftover of #3075 / #35846).
 *
 * Order is a compile-time letter string: A=ASCII U=UTF-8 L=ISO-8859-1 B=8BIT.
 * Empty return means false.
 *
 * Third arg is a flag string ("0"/"1") — an {@code int} third param boxed NestedJIT
 * params and broke LLVM call verify (#35846).
 *
 * Avoid dim-fetch loops on the subject string (hangs under HELPER_O=0 NestedJIT). Order
 * letters and high-byte probes use {@see strpos} / {@see strlen} only (#35846).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_encoding)
 */
final class MbDetectEncodingJitHelper
{
    public static function detectArgv(string $string, string $orderCodes, string $strictFlag): string
    {
        if ('1' === $strictFlag) {
            // reserved
        }

        $asciiOk = self::isAscii($string);
        $utf8Ok = $asciiOk ? true : self::hasUtf8Lead($string);
        $hasUtf8 = false !== \strpos($orderCodes, 'U');
        $hasAscii = false !== \strpos($orderCodes, 'A');
        $hasL = false !== \strpos($orderCodes, 'L');
        $hasB = false !== \strpos($orderCodes, 'B');
        $firstAu = '';
        if ($hasAscii || $hasUtf8) {
            $posA = \strpos($orderCodes, 'A');
            $posU = \strpos($orderCodes, 'U');
            if (false === $posA) {
                $firstAu = 'U';
            } elseif (false === $posU) {
                $firstAu = 'A';
            } elseif ($posU < $posA) {
                $firstAu = 'U';
            } else {
                $firstAu = 'A';
            }
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

        if ($hasAscii && $asciiOk) {
            return 'ASCII';
        }
        if ($hasL) {
            return 'ISO-8859-1';
        }
        if ($hasB) {
            return '8BIT';
        }
        if ($hasUtf8 && $utf8Ok) {
            return 'UTF-8';
        }

        return '';
    }

    private static function isAscii(string $string): bool
    {
        if (false !== \strpos($string, "\xE2")) {
            return false;
        }
        if (false !== \strpos($string, "\xC2")) {
            return false;
        }
        if (false !== \strpos($string, "\xC3")) {
            return false;
        }
        if (false !== \strpos($string, "\xF0")) {
            return false;
        }
        if (false !== \strpos($string, "\xE0")) {
            return false;
        }
        if (false !== \strpos($string, "\x80")) {
            return false;
        }

        return true;
    }

    private static function hasUtf8Lead(string $string): bool
    {
        if (false !== \strpos($string, "\xE2")) {
            return true;
        }
        if (false !== \strpos($string, "\xC2")) {
            return true;
        }
        if (false !== \strpos($string, "\xC3")) {
            return true;
        }
        if (false !== \strpos($string, "\xF0")) {
            return true;
        }
        if (false !== \strpos($string, "\xE0")) {
            return true;
        }

        return false;
    }
}
