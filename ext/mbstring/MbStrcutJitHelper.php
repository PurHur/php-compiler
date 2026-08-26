<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * NestedJIT helpers for mb_strcut() / mb_substr() (#4573 / #27028 / #34256).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strcut) / PHP_FUNCTION(mb_substr).
 *
 * NestedJIT constraints proven on #34256:
 * - No VmMbstring / VmString; omit length uses call-site sentinel -1 (not int-min).
 * - No private helpers in this unit.
 * - Precompute `$endAt = $start + $length` before the char walk.
 * - Use `$n = $sliceEnd - $sliceStart` then `\substr($string, $sliceStart, $n)`.
 * - Prefer `$found == 0` and nested range ifs (no elseif / ternaries).
 * - Do not branch on `$encoding` before the UTF-8 walk (NestedJIT mis-slice).
 * Runtime encoding validation (#34875) — int-returning assert (string-returning NestedJIT throws SIGSEGV).
 */
final class MbStrcutJitHelper
{
    /**
     * Int-returning encoding check — NestedJIT ValueError from string-returning helpers
     * SIGSEGVs under thin AOT; int helpers match {@see MbSearchJitHelper::assertEncodingArgv} (#34875 / #34866).
     *
     * Encoding is Argument #4 for mb_substr / mb_strcut.
     */
    public static function assertEncodingArgv(string $encoding, string $function): int
    {
        if ('' === self::canon($encoding)) {
            // Concat (not sprintf) — NestedJIT sprintf+throw breaks module verify (#34625).
            throw new \ValueError(
                $function.'(): Argument #4 ($encoding) must be a valid encoding, "'.$encoding.'" given'
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

    /** @param int $length negative means cut to end */
    public static function strcutArgv(string $string, int $from, int $length, string $encoding): string
    {
        // Encoding must already be validated via {@see assertEncodingArgv} (#34875).
        if ($from < 0) {
            $from = \strlen($string) + $from;
            if ($from < 0) {
                $from = 0;
            }
        }
        if ($length < 0) {
            return \substr($string, $from);
        }

        return \substr($string, $from, $length);
    }
}

final class MbSubstrJitHelper
{
    /**
     * @param int $length -1 means omitted (to end); call site uses -1 sentinel
     */
    public static function substrArgv(
        string $string,
        int $start,
        int $length,
        string $encoding
    ): string {
        $byteLen = \strlen($string);
        $charLen = 0;
        $bytePos = 0;
        $g = $byteLen + 1;
        while ($bytePos < $byteLen && $g > 0) {
            $g = $g - 1;
            $b = \ord(\substr($string, $bytePos, 1));
            $w = 1;
            if ($b >= 192) {
                if ($b < 224) {
                    if ($bytePos + 1 < $byteLen) {
                        $w = 2;
                    }
                }
            }
            if ($b >= 224) {
                if ($b < 240) {
                    if ($bytePos + 2 < $byteLen) {
                        $w = 3;
                    }
                }
            }
            if ($b >= 240) {
                if ($b < 248) {
                    if ($bytePos + 3 < $byteLen) {
                        $w = 4;
                    }
                }
            }
            $bytePos = $bytePos + $w;
            $charLen = $charLen + 1;
        }
        if ($start < 0) {
            $start = $charLen + $start;
        }
        if ($start < 0) {
            $start = 0;
        }
        if ($start >= $charLen) {
            return '';
        }
        // -1 = omitted length sentinel from JitMbSubstr (#34256).
        if (-1 === $length) {
            $length = $charLen - $start;
        } elseif ($length < 0) {
            $length = $charLen - $start + $length;
            if ($length < 0) {
                return '';
            }
        }
        if ($length <= 0) {
            return '';
        }
        $endAt = $start + $length;
        $charIndex = 0;
        $bytePos = 0;
        $sliceStart = $byteLen;
        $sliceEnd = $byteLen;
        $foundStart = 0;
        $foundEnd = 0;
        $g = $byteLen + 1;
        while ($bytePos < $byteLen && $g > 0) {
            $g = $g - 1;
            if ($foundStart == 0) {
                if ($charIndex == $start) {
                    $sliceStart = $bytePos;
                    $foundStart = 1;
                }
            }
            if ($foundEnd == 0) {
                if ($charIndex == $endAt) {
                    $sliceEnd = $bytePos;
                    $foundEnd = 1;
                }
            }
            $b = \ord(\substr($string, $bytePos, 1));
            $w = 1;
            if ($b >= 192) {
                if ($b < 224) {
                    if ($bytePos + 1 < $byteLen) {
                        $w = 2;
                    }
                }
            }
            if ($b >= 224) {
                if ($b < 240) {
                    if ($bytePos + 2 < $byteLen) {
                        $w = 3;
                    }
                }
            }
            if ($b >= 240) {
                if ($b < 248) {
                    if ($bytePos + 3 < $byteLen) {
                        $w = 4;
                    }
                }
            }
            $bytePos = $bytePos + $w;
            $charIndex = $charIndex + 1;
        }
        if ($foundStart == 0) {
            return '';
        }
        if ($foundEnd == 0) {
            $sliceEnd = $byteLen;
        }
        $n = $sliceEnd - $sliceStart;

        return \substr($string, $sliceStart, $n);
    }
}
