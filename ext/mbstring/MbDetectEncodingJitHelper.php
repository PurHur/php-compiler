<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_detect_encoding() NestedJIT runtime (#34358 / #35846).
 *
 * Order is a compile-time letter string: A=ASCII U=UTF-8 L=ISO-8859-1 B=8BIT.
 * Empty return means false. No VmMbstring / MbstringState.
 * Iteration mirrors {@see MbScrubJitHelper} (strlen + for) — isset length loops
 * SIGSEGV/malloc-abort under thin AOT NestedJIT (#35846).
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
        $n = \strlen($orderCodes);
        for ($i = 0; $i < $n; ++$i) {
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

        for ($i = 0; $i < $n; ++$i) {
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
        }

        if ($hasUtf8 && $utf8Ok) {
            return 'UTF-8';
        }

        return '';
    }

    private static function isAscii(string $string): bool
    {
        $len = \strlen($string);
        for ($i = 0; $i < $len; ++$i) {
            if ($string[$i] > "\x7F") {
                return false;
            }
        }

        return true;
    }

    private static function isValidUtf8(string $string): bool
    {
        $len = \strlen($string);
        if (0 === $len) {
            return true;
        }
        $i = 0;
        while ($i < $len) {
            $ch = $string[$i];
            if ($ch <= "\x7F") {
                ++$i;

                continue;
            }
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
            for ($j = 1; $j <= $need; ++$j) {
                $nextCh = $string[$i + $j];
                if ($nextCh < "\x80" || $nextCh > "\xBF") {
                    return false;
                }
            }
            $i += $need + 1;
        }

        return true;
    }
}
