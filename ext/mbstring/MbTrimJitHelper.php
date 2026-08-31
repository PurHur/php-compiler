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
 * Copy `$payload = $value.''` before any loop — NestedJIT zeros param slots (#34881 peer).
 * Rtrim walks backward with `$keep` / `$pos - 1` — forward `$last = $i` miscompiles (#34396).
 *
 * Runtime encoding via {@see assertEncodingArgv} (#35199 leftover of #34379 / peer #35161).
 *
 * Default charset: ASCII ws + U+00A0 (C2 A0). php-src: ext/mbstring/mbstring.c
 */
final class MbTrimJitHelper
{
    /**
     * Int-returning encoding check — NestedJIT ValueError from string-returning helpers
     * SIGSEGVs under thin AOT; int helpers match {@see MbScrubJitHelper::assertEncodingArgv}.
     *
     * Argument #3 ($encoding) for mb_trim / mb_ltrim / mb_rtrim.
     */
    public static function assertEncodingArgv(string $encoding, string $function): int
    {
        $ok = 0;
        if ('UTF-8' === $encoding || 'utf-8' === $encoding || 'UTF8' === $encoding || 'utf8' === $encoding) {
            $ok = 1;
        }
        if (
            'ASCII' === $encoding || 'ascii' === $encoding
            || 'US-ASCII' === $encoding || 'us-ascii' === $encoding
        ) {
            $ok = 1;
        }
        if ('8BIT' === $encoding || '8bit' === $encoding || 'BINARY' === $encoding || 'binary' === $encoding) {
            $ok = 1;
        }
        if (0 === $ok) {
            // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
            throw new \ValueError(
                $function.'(): Argument #3 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }

        return 1;
    }

    public static function trimDefault(string $value, string $encoding): string
    {
        // Encoding already validated via {@see assertEncodingArgv} (#35199).
        // UTF-8 / ASCII / 8BIT share the default trim set for ASCII ws (+ UTF-8 NBSP);
        // php-src mb_trim uses single-byte trim for ASCII/8BIT (VmMbstring::trimString).
        unset($encoding);

        $payload = $value.'';

        return self::trimRightBody(self::trimLeftBody($payload));
    }

    public static function ltrimDefault(string $value, string $encoding): string
    {
        unset($encoding);

        return self::trimLeftBody($value.'');
    }

    public static function rtrimDefault(string $value, string $encoding): string
    {
        unset($encoding);

        return self::trimRightBody($value.'');
    }

    public static function trimChars(string $value, string $what): string
    {
        $payload = $value.'';
        $chars = $what.'';
        if ('' === $chars) {
            return $payload;
        }

        return self::trimCharsRightBody(self::trimCharsLeftBody($payload, $chars), $chars);
    }

    private static function trimLeftBody(string $payload): string
    {
        $n = \strlen($payload);
        $out = '';
        $started = 0;
        $prev = '';
        for ($i = 0; $i < $n; ++$i) {
            $c = $payload[$i];
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

    private static function trimRightBody(string $payload): string
    {
        $n = \strlen($payload);
        if ($n <= 0) {
            return '';
        }
        $keep = $n + 0;
        $pos = $n - 1;
        $done = 0;
        $guard = $n + 1;
        while ($pos >= 0 && $guard > 0 && 0 === $done) {
            $guard = $guard - 1;
            $c = $payload[$pos];
            if ("\xA0" === $c && $pos > 0 && "\xC2" === $payload[$pos - 1]) {
                $keep = $pos - 1;
                $pos = $pos - 2;
            } elseif (1 === self::defaultWsFlag($c)) {
                $keep = $pos + 0;
                $pos = $pos - 1;
            } else {
                $done = 1;
            }
        }

        return self::copyPrefix($payload, $keep);
    }

    private static function defaultWsFlag(string $c): int
    {
        if (' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c
            || "\0" === $c || "\x0B" === $c) {
            return 1;
        }

        return 0;
    }

    private static function trimCharsLeftBody(string $payload, string $what): string
    {
        $n = \strlen($payload);
        $wlen = \strlen($what);
        $out = '';
        $started = 0;
        for ($i = 0; $i < $n; ++$i) {
            $c = $payload[$i];
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

    private static function trimCharsRightBody(string $payload, string $what): string
    {
        $n = \strlen($payload);
        $wlen = \strlen($what);
        if ($n <= 0) {
            return '';
        }
        $keep = $n + 0;
        $pos = $n - 1;
        $done = 0;
        $guard = $n + 1;
        while ($pos >= 0 && $guard > 0 && 0 === $done) {
            $guard = $guard - 1;
            $c = $payload[$pos];
            $hit = 0;
            for ($k = 0; $k < $wlen; ++$k) {
                if ($what[$k] === $c) {
                    $hit = 1;
                }
            }
            if (0 === $hit) {
                $done = 1;
            } else {
                $keep = $pos + 0;
                $pos = $pos - 1;
            }
        }

        return self::copyPrefix($payload, $keep);
    }

    private static function copyPrefix(string $payload, int $len): string
    {
        $want = $len + 0;
        $out = '';
        for ($i = 0; $i < $want; ++$i) {
            $out .= $payload[$i];
        }

        return $out;
    }
}
