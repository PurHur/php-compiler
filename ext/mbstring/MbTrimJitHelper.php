<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_trim() NestedJIT runtime (#34379 leftover of #5957/#23883).
 *
 * Public two-string leaves like {@see MbScrubJitHelper::scrubArgv} (`__string__*`).
 * Private bodies: ascending for-loops only, no `break`/`continue`/strrev (those
 * SIGSEGV under thin AOT when several leaves share a module).
 *
 * Byte access via $value[$i] — NestedJIT substr() mis-fires under thin AOT (#34338).
 * Rtrim NBSP uses a C2-hold deferral — `$last = $i - 1` / `$last === $n - 1` miscompile
 * under thin AOT (#34396 leftover).
 *
 * Default charset: ASCII ws + U+00A0 (C2 A0). php-src: ext/mbstring/mbstring.c
 */
final class MbTrimJitHelper
{
    public static function trimDefault(string $value, string $encoding): string
    {
        if ('8BIT' === $encoding) {
            return $value;
        }

        return self::trimRightBody(self::trimLeftBody($value));
    }

    public static function ltrimDefault(string $value, string $encoding): string
    {
        if ('8BIT' === $encoding) {
            return $value;
        }

        return self::trimLeftBody($value);
    }

    public static function rtrimDefault(string $value, string $encoding): string
    {
        if ('8BIT' === $encoding) {
            return $value;
        }

        return self::trimRightBody($value);
    }

    public static function trimChars(string $value, string $what): string
    {
        if ('' === $what) {
            return $value;
        }

        return self::trimCharsRightBody(self::trimCharsLeftBody($value, $what), $what);
    }

    private static function trimLeftBody(string $value): string
    {
        $n = \strlen($value);
        $out = '';
        $started = 0;
        $prev = '';
        for ($i = 0; $i < $n; ++$i) {
            $c = $value[$i];
            $ws = 0;
            if (' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c
                || "\0" === $c || "\x0B" === $c) {
                $ws = 1;
            } elseif ("\xA0" === $c && "\xC2" === $prev) {
                $ws = 1;
            }
            if ("\xC2" === $c) {
                // Hold C2 until next byte decides NBSP vs content.
                $prev = $c;
            } else {
                if (0 === $started) {
                    if (1 === $ws) {
                        // skip leading ws (incl trailing A0 of NBSP)
                    } else {
                        if ("\xC2" === $prev) {
                            $out .= $prev;
                        }
                        $started = 1;
                        $out .= $c;
                    }
                } else {
                    if ("\xC2" === $prev) {
                        $out .= $prev;
                    }
                    $out .= $c;
                }
                $prev = $c;
            }
        }
        if ("\xC2" === $prev && 1 === $started) {
            $out .= $prev;
        }

        return $out;
    }

    private static function trimRightBody(string $value): string
    {
        $n = \strlen($value);
        $last = -1;
        $pendingC2 = -1;
        for ($i = 0; $i < $n; ++$i) {
            $c = $value[$i];
            if ($pendingC2 >= 0) {
                if ("\xA0" === $c) {
                    $pendingC2 = -1;
                } else {
                    $last = $pendingC2;
                    $pendingC2 = -1;
                    if (0 === self::defaultWsFlag($c)) {
                        $last = $i;
                    }
                }
            } elseif ("\xC2" === $c) {
                $pendingC2 = $i;
            } elseif (0 === self::defaultWsFlag($c)) {
                $last = $i;
            }
        }
        if ($pendingC2 >= 0) {
            $last = $pendingC2;
        }
        if ($last < 0) {
            return '';
        }

        return self::copyPrefix($value, $last + 1);
    }

    private static function defaultWsFlag(string $c): int
    {
        if (' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c
            || "\0" === $c || "\x0B" === $c) {
            return 1;
        }

        return 0;
    }

    private static function trimCharsLeftBody(string $value, string $what): string
    {
        $n = \strlen($value);
        $wlen = \strlen($what);
        $out = '';
        $started = 0;
        for ($i = 0; $i < $n; ++$i) {
            $c = $value[$i];
            if (0 === $started) {
                $hit = 0;
                for ($k = 0; $k < $wlen; ++$k) {
                    if ($what[$k] === $c) {
                        $hit = 1;
                    }
                }
                if (0 === $hit) {
                    $started = 1;
                    $out .= $c;
                }
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    private static function trimCharsRightBody(string $value, string $what): string
    {
        $n = \strlen($value);
        $wlen = \strlen($what);
        $last = -1;
        for ($i = 0; $i < $n; ++$i) {
            $c = $value[$i];
            $hit = 0;
            for ($k = 0; $k < $wlen; ++$k) {
                if ($what[$k] === $c) {
                    $hit = 1;
                }
            }
            if (0 === $hit) {
                $last = $i;
            }
        }
        if ($last < 0) {
            return '';
        }

        return self::copyPrefix($value, $last + 1);
    }

    private static function copyPrefix(string $value, int $len): string
    {
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $out .= $value[$i];
        }

        return $out;
    }
}
