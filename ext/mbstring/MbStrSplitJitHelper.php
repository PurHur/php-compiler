<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_str_split() NestedJIT peel for thin AOT (#26870 / #34278 / #34880, php-in-PHP).
 *
 * Thin AOT cannot NestedJIT-construct {@see \PHPCompiler\VM\HashTable} (peer
 * {@see \PHPCompiler\ext\standard\JitExplode} / #27660). Returns a
 * record-separator-joined string; {@see JitMbStrSplit} rebuilds the HT via explode.
 *
 * NestedJIT-safe UTF-8 peel mirrors {@see MbStrwidthJitHelper} (#34270):
 * isset-index length, no VmMbstring, char-index walk via utf8Substr.
 *
 * Runtime encoding via assertEncodingArgv (#34880 leftover of #34278 / peer #34875).
 *
 * SSOT (VM / compile-time fold): {@see VmMbstring::strSplit}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_str_split)
 */
final class MbStrSplitJitHelper
{
    public const JOIN_DELIM = "\x1E";

    /**
     * Int-returning encoding check — NestedJIT ValueError from string-returning helpers
     * SIGSEGVs under thin AOT; int helpers match {@see MbStrcutJitHelper::assertEncodingArgv} (#34880).
     *
     * Encoding is Argument #3 for mb_str_split.
     */
    public static function assertEncodingArgv(string $encoding, string $function): int
    {
        if ('' === self::canon($encoding)) {
            // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
            throw new \ValueError(
                $function.'(): Argument #3 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }

        return 1;
    }

    private static function canon(string $encoding): string
    {
        if ('UTF-8' === $encoding || 'utf-8' === $encoding || 'UTF8' === $encoding || 'utf8' === $encoding) {
            return 'UTF-8';
        }
        if (
            'ASCII' === $encoding || 'ascii' === $encoding
            || 'US-ASCII' === $encoding || 'us-ascii' === $encoding
        ) {
            return 'ASCII';
        }
        if ('8BIT' === $encoding || '8bit' === $encoding || 'BINARY' === $encoding || 'binary' === $encoding) {
            return '8BIT';
        }

        return '';
    }

    public static function strSplitArgv(string $string, int $length, string $encoding): string
    {
        // Encoding must already be validated via {@see assertEncodingArgv} (#34880).
        if ($length <= 0) {
            return '';
        }

        $enc = self::canon($encoding);
        $single = 0;
        if ('ASCII' === $enc) {
            $single = 1;
        }
        if ('8BIT' === $enc) {
            $single = 1;
        }
        if (1 === $single) {
            return self::joinByteChunks($string, $length);
        }

        return self::joinUtf8Chunks($string, $length);
    }

    private static function joinByteChunks(string $string, int $length): string
    {
        $byteLen = self::byteLength($string);
        if (0 === $byteLen) {
            return '';
        }

        $joined = '';
        $pos = 0;
        $first = 1;
        $delim = "\x1E";
        $guard = $byteLen + 1;
        while ($pos < $byteLen && $guard > 0) {
            $guard = $guard - 1;
            $take = $length;
            if ($pos + $take > $byteLen) {
                $take = $byteLen - $pos;
            }
            $part = \substr($string, $pos, $take);
            if (1 === $first) {
                $joined = $part;
                $first = 0;
            } else {
                $joined = $joined.$delim.$part;
            }
            $pos = $pos + $length;
        }

        return $joined;
    }

    private static function joinUtf8Chunks(string $string, int $length): string
    {
        $charLen = self::utf8CharLength($string);
        if (0 === $charLen) {
            return '';
        }

        $joined = '';
        $pos = 0;
        $first = 1;
        $delim = "\x1E";
        $guard = $charLen + 1;
        while ($pos < $charLen && $guard > 0) {
            $guard = $guard - 1;
            $take = $length;
            if ($pos + $take > $charLen) {
                $take = $charLen - $pos;
            }
            $part = self::utf8Substr($string, $pos, $take);
            if (1 === $first) {
                $joined = $part;
                $first = 0;
            } else {
                $joined = $joined.$delim.$part;
            }
            $pos = $pos + $length;
        }

        return $joined;
    }

    /** NestedJIT-safe length: strlen silent-0 here (#34264). */
    private static function byteLength(string $string): int
    {
        $n = 0;
        while (isset($string[$n])) {
            ++$n;
            if ($n > 1048576) {
                break;
            }
        }

        return $n;
    }

    private static function utf8CharLength(string $string): int
    {
        $n = 0;
        $i = 0;
        $len = self::byteLength($string);
        while ($i < $len) {
            $b = \ord($string[$i]);
            if ($b < 0x80) {
                $step = 1;
            } elseif ($b < 0xE0) {
                $step = 2;
            } elseif ($b < 0xF0) {
                $step = 3;
            } else {
                $step = 4;
            }
            $i += $step;
            ++$n;
            if ($n > $len) {
                break;
            }
        }

        return $n;
    }

    private static function utf8Substr(string $string, int $charFrom, int $charCount): string
    {
        if ($charCount <= 0) {
            return '';
        }
        $i = 0;
        $len = self::byteLength($string);
        $seen = 0;
        while ($i < $len && $seen < $charFrom) {
            $b = \ord($string[$i]);
            if ($b < 0x80) {
                $step = 1;
            } elseif ($b < 0xE0) {
                $step = 2;
            } elseif ($b < 0xF0) {
                $step = 3;
            } else {
                $step = 4;
            }
            $i += $step;
            ++$seen;
        }
        if ($i >= $len) {
            return '';
        }
        $start = $i;
        $taken = 0;
        while ($i < $len && $taken < $charCount) {
            $b = \ord($string[$i]);
            if ($b < 0x80) {
                $step = 1;
            } elseif ($b < 0xE0) {
                $step = 2;
            } elseif ($b < 0xF0) {
                $step = 3;
            } else {
                $step = 4;
            }
            $i += $step;
            ++$taken;
        }

        return \substr($string, $start, $i - $start);
    }
}
