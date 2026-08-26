<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * NestedJIT helpers for mb_strcut() / mb_substr() (#4573 / #27028 / #34256).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strcut) / PHP_FUNCTION(mb_substr).
 *
 * NestedJIT constraints proven on #34256 / #34881:
 * - No VmMbstring / VmString; omit length uses call-site sentinel -1 (not int-min).
 * - No private helpers in this unit.
 * - Never reassign `$start` / `$from` / `$length` params — NestedJIT treats a rewritten
 *   param as 0 on all paths (#34881 leftover of #34846). Copy via `$startAt = $start + 0`
 *   (a plain `$startAt = $start` is also zeroed under NestedJIT).
 * - Precompute `$endAt = $startAt + $lenAt` before the char walk.
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
        // Never reassign $from / $length — NestedJIT zeros rewritten params (#34881).
        // Plain `$fromAt = $from` is also zeroed; use `+ 0` so NestedJIT keeps the runtime int.
        $fromAt = $from + 0;
        $byteLen = \strlen($string);
        if ($from < 0) {
            $fromAt = $byteLen + $from;
            if ($fromAt < 0) {
                $fromAt = 0;
            }
        }
        $lenAt = $length + 0;
        if ($length < 0) {
            $lenAt = ($byteLen - $fromAt) + $length;
            if ($lenAt < 0) {
                $lenAt = 0;
            }
        }
        if ($fromAt > $byteLen) {
            return '';
        }
        if ($lenAt == 0) {
            return '';
        }
        // UTF-8 byte cut with character-boundary alignment (php-src mb_strcut / utf8AlignByteStart).
        // Mid-character $from backs up to the lead byte — without this, from=1 on "über" mis-slices.
        $p = 0;
        $lastWidth = 1;
        $g = $byteLen + 1;
        while ($p < $fromAt && $p < $byteLen && $g > 0) {
            $g = $g - 1;
            $b = \ord(\substr($string, $p, 1));
            $w = 1;
            if ($b >= 192) {
                if ($b < 224) {
                    if ($p + 1 < $byteLen) {
                        $w = 2;
                    }
                }
            }
            if ($b >= 224) {
                if ($b < 240) {
                    if ($p + 2 < $byteLen) {
                        $w = 3;
                    }
                }
            }
            if ($b >= 240) {
                if ($b < 248) {
                    if ($p + 3 < $byteLen) {
                        $w = 4;
                    }
                }
            }
            $lastWidth = $w;
            $p = $p + $w;
        }
        if ($p > $fromAt) {
            $p = $p - $lastWidth;
        }
        $sliceStart = $p;
        if ($sliceStart >= $byteLen) {
            return '';
        }
        if ($lenAt >= $byteLen - $sliceStart) {
            return \substr($string, $sliceStart);
        }
        $target = $sliceStart + $lenAt;
        $p = $sliceStart;
        $lastWidth = 1;
        $g = $byteLen + 1;
        while ($p < $target && $p < $byteLen && $g > 0) {
            $g = $g - 1;
            $b = \ord(\substr($string, $p, 1));
            $w = 1;
            if ($b >= 192) {
                if ($b < 224) {
                    if ($p + 1 < $byteLen) {
                        $w = 2;
                    }
                }
            }
            if ($b >= 224) {
                if ($b < 240) {
                    if ($p + 2 < $byteLen) {
                        $w = 3;
                    }
                }
            }
            if ($b >= 240) {
                if ($b < 248) {
                    if ($p + 3 < $byteLen) {
                        $w = 4;
                    }
                }
            }
            $lastWidth = $w;
            $p = $p + $w;
        }
        if ($p > $target) {
            $p = $p - $lastWidth;
        }
        $n = $p - $sliceStart;

        return \substr($string, $sliceStart, $n);
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
        // Never reassign $start / $length — NestedJIT zeros rewritten params on all paths (#34881).
        // Plain `$startAt = $start` is also zeroed; use `+ 0` so NestedJIT keeps the runtime int.
        $startAt = $start + 0;
        if ($start < 0) {
            $startAt = $charLen + $start;
        }
        if ($startAt < 0) {
            $startAt = 0;
        }
        if ($startAt >= $charLen) {
            return '';
        }
        // -1 = omitted length sentinel from JitMbSubstr (#34256).
        $lenAt = $length + 0;
        if (-1 === $length) {
            $lenAt = $charLen - $startAt;
        } elseif ($length < 0) {
            $lenAt = $charLen - $startAt + $length;
            if ($lenAt < 0) {
                return '';
            }
        }
        if ($lenAt <= 0) {
            return '';
        }
        $endAt = $startAt + $lenAt;
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
                if ($charIndex == $startAt) {
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
