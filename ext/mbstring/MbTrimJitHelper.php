<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_trim() / mb_ltrim() / mb_rtrim() NestedJIT runtime (#34379 leftover of #5957/#23883).
 *
 * Single-function leaf kept small — NestedJIT of large UTF-8 CFGs SIGSEGVs under thin AOT.
 * Handles ASCII default whitespace + U+00A0 (C2 A0), mode, and literal `$characters`.
 * Broader Unicode defaults (U+1680…U+3000) stay on VM / compile-time fold.
 *
 * Int params: compare `$mode` / `$useDefaultWhat` directly (NestedJIT zeros copied locals —
 * peer {@see MbStrwidthJitHelper}). Haystack length via isset-index; `$what` length is
 * passed as `$whatLen` (NestedJIT isset on helper string params is unreliable).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_trim) / mb_ltrim / mb_rtrim
 */
final class MbTrimJitHelper
{
    /**
     * @param int $useDefaultWhat 1 = default trim set; 0 = use $what ('' = no trim)
     */
    public static function trimArgv(
        string $value,
        string $what,
        string $encoding,
        int $mode,
        int $useDefaultWhat,
        int $whatLen
    ): string {
        unset($encoding);

        $doLeft = 0;
        $doRight = 0;
        if (1 === $mode || 3 === $mode) {
            $doLeft = 1;
        }
        if (2 === $mode || 3 === $mode) {
            $doRight = 1;
        }

        $byteLen = 0;
        while (isset($value[$byteLen])) {
            $byteLen = $byteLen + 1;
            if ($byteLen > 1048576) {
                break;
            }
        }
        if (0 === $byteLen) {
            return '';
        }

        $start = 0;
        if (1 === $doLeft) {
            while ($start < $byteLen) {
                $ch = \substr($value, $start, 1);
                $w = 1;
                $trim = 0;
                if (1 === $useDefaultWhat) {
                    if (' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch
                        || "\0" === $ch || "\x0B" === $ch || "\x0C" === $ch || "\x85" === $ch) {
                        $trim = 1;
                    } elseif ("\xC2" === $ch && $start + 1 < $byteLen
                        && "\xA0" === \substr($value, $start + 1, 1)) {
                        $trim = 1;
                        $w = 2;
                    }
                } else {
                    $wi = 0;
                    while ($wi < $whatLen) {
                        if (\substr($what, $wi, 1) === $ch) {
                            $trim = 1;
                            break;
                        }
                        $wi = $wi + 1;
                    }
                }
                if (0 === $trim) {
                    break;
                }
                $start = $start + $w;
            }
        }

        $end = $byteLen;
        if (1 === $doRight) {
            while ($end > $start) {
                // Prefer NBSP (2 bytes) at end when present
                $w = 1;
                $ch = \substr($value, $end - 1, 1);
                $trim = 0;
                if (1 === $useDefaultWhat) {
                    if ($end - $start >= 2 && "\xC2" === \substr($value, $end - 2, 1)
                        && "\xA0" === $ch) {
                        $trim = 1;
                        $w = 2;
                    } elseif (' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch
                        || "\0" === $ch || "\x0B" === $ch || "\x0C" === $ch || "\x85" === $ch) {
                        $trim = 1;
                    }
                } else {
                    $wi = 0;
                    while ($wi < $whatLen) {
                        if (\substr($what, $wi, 1) === $ch) {
                            $trim = 1;
                            break;
                        }
                        $wi = $wi + 1;
                    }
                }
                if (0 === $trim) {
                    break;
                }
                $end = $end - $w;
            }
        }

        if (0 === $start && $end === $byteLen) {
            return $value;
        }

        return \substr($value, $start, $end - $start);
    }
}
