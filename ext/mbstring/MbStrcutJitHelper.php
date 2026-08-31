<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * NestedJIT helpers for mb_strcut() / mb_substr() (#4573 / #27028 / #34256 / #34875 / #34881).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strcut) / PHP_FUNCTION(mb_substr).
 *
 * NestedJIT constraints proven on #34256:
 * - No VmMbstring / VmString; omit length uses call-site sentinel -1 (not int-min).
 * - No private helpers in this unit.
 * - Precompute `$endAt = $startAt + $lenAt` before the char walk.
 * - Use `$n = $sliceEnd - $sliceStart` then `\substr($string, $sliceStart, $n)`.
 * - Prefer `$found == 0` and nested range ifs (no elseif / ternaries).
 * - Do not branch on `$encoding` before the UTF-8 walk (NestedJIT mis-slice).
 * - Never reassign `$start` / `$length` / `$from` params — NestedJIT then treats them as 0
 *   (#34256 / #34881). Plain `$startAt = $start` is also zeroed; copy via `$startAt = $start + 0`
 *   before any loop. mb_substr slice walk uses skip/take counters — `$charIndex == $startAt`
 *   mis-lowers after an earlier charLen loop (#34881 re-open).
 *
 * Runtime encoding validation (#34875) — int-returning assert (string-returning NestedJIT throws SIGSEGV).
 */
final class MbStrcutJitHelper
{
    /**
     * Int-returning encoding check — NestedJIT ValueError from string-returning helpers
     * SIGSEGVs under thin AOT; int helpers match {@see MbSearchJitHelper::assertEncodingArgv} (#34875 / #34866).
     *
     * Encoding is Argument #4 for mb_substr / mb_strcut. Canon is inlined (no private helpers).
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
                $function.'(): Argument #4 ($encoding) must be a valid encoding, "'.$encoding.'" given'
            );
        }

        return 1;
    }

    /** @param int $length negative means cut to end */
    public static function strcutArgv(string $string, int $from, int $length, string $encoding): string
    {
        // Encoding must already be validated via {@see assertEncodingArgv} (#34875).
        // Copy params before any loop — NestedJIT zeros param slots after locals run (#34881).
        $fromAt = $from + 0;
        $lenAt = $length + 0;
        // UTF-8 char-boundary snap inlined (php-src mb_strcut; no helper calls in this unit).
        $byteLen = \strlen($string);
        if ($fromAt < 0) {
            $fromAt = $byteLen + $fromAt;
            if ($fromAt < 0) {
                $fromAt = 0;
            }
        }
        if ($fromAt >= $byteLen) {
            return '';
        }
        if ($fromAt > 0) {
            $b = \ord(\substr($string, $fromAt, 1));
            if ($b >= 128) {
                if ($b < 192) {
                    $back = $fromAt;
                    $g = 4;
                    while ($back > 0 && $g > 0) {
                        $g = $g - 1;
                        $back = $back - 1;
                        $bb = \ord(\substr($string, $back, 1));
                        if ($bb < 128) {
                            $fromAt = $back;
                            $g = 0;
                        }
                        if ($bb >= 192) {
                            $fromAt = $back;
                            $g = 0;
                        }
                    }
                }
            }
        }
        if ($lenAt < 0) {
            return \substr($string, $fromAt);
        }
        if ($lenAt <= 0) {
            return '';
        }
        $end = $fromAt + $lenAt;
        if ($end >= $byteLen) {
            $lenAt = $byteLen - $fromAt;
        }
        if ($end < $byteLen) {
            $eb = \ord(\substr($string, $end, 1));
            if ($eb >= 128) {
                if ($eb < 192) {
                    $back = $end;
                    $g = 4;
                    while ($back > $fromAt && $g > 0) {
                        $g = $g - 1;
                        $back = $back - 1;
                        $bb = \ord(\substr($string, $back, 1));
                        if ($bb < 128) {
                            $lenAt = $back - $fromAt;
                            $g = 0;
                        }
                        if ($bb >= 192) {
                            $lenAt = $back - $fromAt;
                            $g = 0;
                        }
                    }
                }
            }
        }

        return \substr($string, $fromAt, $lenAt);
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
        // Copy params before any loop — NestedJIT zeros param slots after locals run (#34881 re-open).
        $startAt = $start + 0;
        $lenAt = $length + 0;
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
        if ($startAt < 0) {
            $startAt = $charLen + $startAt;
        }
        if ($startAt < 0) {
            $startAt = 0;
        }
        if ($startAt >= $charLen) {
            return '';
        }
        if (-1 === $lenAt) {
            $lenAt = $charLen - $startAt;
        } elseif ($lenAt < 0) {
            $lenAt = $charLen - $startAt + $lenAt;
            if ($lenAt < 0) {
                return '';
            }
        }
        if ($lenAt <= 0) {
            return '';
        }
        // Skip/take char walk — NestedJIT mis-lowers `$charIndex == $startAt` after an earlier loop (#34881).
        $skip = $startAt + 0;
        $take = $lenAt + 0;
        $walkPos = 0;
        $walkGuard = $byteLen + 1;
        while ($walkPos < $byteLen && $walkGuard > 0 && $skip > 0) {
            $walkGuard = $walkGuard - 1;
            $bb = \ord(\substr($string, $walkPos, 1));
            $ww = 1;
            if ($bb >= 192) {
                if ($bb < 224) {
                    if ($walkPos + 1 < $byteLen) {
                        $ww = 2;
                    }
                }
            }
            if ($bb >= 224) {
                if ($bb < 240) {
                    if ($walkPos + 2 < $byteLen) {
                        $ww = 3;
                    }
                }
            }
            if ($bb >= 240) {
                if ($bb < 248) {
                    if ($walkPos + 3 < $byteLen) {
                        $ww = 4;
                    }
                }
            }
            $walkPos = $walkPos + $ww;
            $skip = $skip - 1;
        }
        if ($skip > 0) {
            return '';
        }
        $sliceStart = $walkPos;
        $sliceEnd = $byteLen;
        $walkGuard = $byteLen + 1;
        while ($walkPos < $byteLen && $walkGuard > 0 && $take > 0) {
            $walkGuard = $walkGuard - 1;
            $bb = \ord(\substr($string, $walkPos, 1));
            $ww = 1;
            if ($bb >= 192) {
                if ($bb < 224) {
                    if ($walkPos + 1 < $byteLen) {
                        $ww = 2;
                    }
                }
            }
            if ($bb >= 224) {
                if ($bb < 240) {
                    if ($walkPos + 2 < $byteLen) {
                        $ww = 3;
                    }
                }
            }
            if ($bb >= 240) {
                if ($bb < 248) {
                    if ($walkPos + 3 < $byteLen) {
                        $ww = 4;
                    }
                }
            }
            $walkPos = $walkPos + $ww;
            $take = $take - 1;
        }
        $sliceEnd = $walkPos;
        $n = $sliceEnd - $sliceStart;

        return \substr($string, $sliceStart, $n);
    }
}
