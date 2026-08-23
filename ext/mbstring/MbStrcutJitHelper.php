<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * NestedJIT helpers for mb_strcut() / mb_substr() (#4573 / #27028 / #34256).
 *
 * Constraints (thin AOT NestedJIT):
 * - No VmMbstring / VmString (silent-return / SIGSEGV).
 * - No private methods (omitted when only one COMPILED_HELPERS symbol is linked).
 * - No ternaries (NestedJIT assign-type errors).
 * - No PHP_INT_MIN in helper bodies (yields empty under NestedJIT).
 * - *Argv names bypass stale helper-runtime cache.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strcut), PHP_FUNCTION(mb_substr)
 */
final class MbStrcutJitHelper
{
    /** @param int $length negative → omitted (to end) */
    public static function strcutArgv(string $string, int $from, int $length, string $encoding): string
    {
        $byteLen = \strlen($string);
        if ($length < 0) {
            $length = $byteLen;
        }
        if ($from < 0) {
            $from = $byteLen + $from;
            if ($from < 0) {
                $from = 0;
            }
        }
        if ($from > $byteLen) {
            return '';
        }
        if (0 === $length) {
            return '';
        }
        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            $max = $byteLen - $from;
            if ($length > $max) {
                $length = $max;
            }

            return \substr($string, $from, $length);
        }

        $p = 0;
        $lastWidth = 1;
        while ($p < $from && $p < $byteLen) {
            $byte = \ord(\substr($string, $p, 1));
            $lastWidth = 1;
            if ($byte >= 240 && $byte < 248) {
                if ($p + 3 < $byteLen) {
                    $lastWidth = 4;
                }
            } elseif ($byte >= 224 && $byte < 240) {
                if ($p + 2 < $byteLen) {
                    $lastWidth = 3;
                }
            } elseif ($byte >= 192 && $byte < 224) {
                if ($p + 1 < $byteLen) {
                    $lastWidth = 2;
                }
            }
            $p = $p + $lastWidth;
        }
        if ($p > $from) {
            $p = $p - $lastWidth;
        }
        $start = $p;
        if ($start >= $byteLen) {
            return '';
        }
        $remain = $byteLen - $start;
        if ($length >= $remain) {
            return \substr($string, $start, $remain);
        }
        $target = $start + $length;
        $p = $start;
        $lastWidth = 1;
        while ($p < $target && $p < $byteLen) {
            $byte = \ord(\substr($string, $p, 1));
            $lastWidth = 1;
            if ($byte >= 240 && $byte < 248) {
                if ($p + 3 < $byteLen) {
                    $lastWidth = 4;
                }
            } elseif ($byte >= 224 && $byte < 240) {
                if ($p + 2 < $byteLen) {
                    $lastWidth = 3;
                }
            } elseif ($byte >= 192 && $byte < 224) {
                if ($p + 1 < $byteLen) {
                    $lastWidth = 2;
                }
            }
            $p = $p + $lastWidth;
        }
        if ($p > $target) {
            $p = $p - $lastWidth;
        }

        return \substr($string, $start, $p - $start);
    }
}

final class MbSubstrJitHelper
{
    /**
     * @param int $hasLength 0 = omitted/null length (to end); 1 = use $length
     */
    public static function substrArgv(
        string $string,
        int $start,
        int $length,
        int $hasLength,
        string $encoding
    ): string {
        $byteLen = \strlen($string);

        if ('ASCII' === $encoding || '8BIT' === $encoding) {
            $charLen = $byteLen;
            if ($start < 0) {
                $start = $start + $charLen;
            }
            if ($start < 0) {
                $start = 0;
            }
            if ($start >= $charLen) {
                return '';
            }
            if (0 === $hasLength) {
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

            return \substr($string, $start, $length);
        }

        $charLen = 0;
        $i = 0;
        $guard = $byteLen + 1;
        while ($i < $byteLen && $guard > 0) {
            $guard = $guard - 1;
            $byte = \ord(\substr($string, $i, 1));
            $step = 1;
            if ($byte >= 240 && $byte < 248) {
                if ($i + 3 < $byteLen) {
                    $step = 4;
                }
            } elseif ($byte >= 224 && $byte < 240) {
                if ($i + 2 < $byteLen) {
                    $step = 3;
                }
            } elseif ($byte >= 192 && $byte < 224) {
                if ($i + 1 < $byteLen) {
                    $step = 2;
                }
            }
            $i = $i + $step;
            $charLen = $charLen + 1;
        }
        if ($start < 0) {
            $start = $start + $charLen;
        }
        if ($start < 0) {
            $start = 0;
        }
        if ($start >= $charLen) {
            return '';
        }
        if (0 === $hasLength) {
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
        $bytePos = 0;
        $skipped = 0;
        while ($skipped < $start && $bytePos < $byteLen) {
            $byte = \ord(\substr($string, $bytePos, 1));
            $w = 1;
            if ($byte >= 240 && $byte < 248) {
                if ($bytePos + 3 < $byteLen) {
                    $w = 4;
                }
            } elseif ($byte >= 224 && $byte < 240) {
                if ($bytePos + 2 < $byteLen) {
                    $w = 3;
                }
            } elseif ($byte >= 192 && $byte < 224) {
                if ($bytePos + 1 < $byteLen) {
                    $w = 2;
                }
            }
            $bytePos = $bytePos + $w;
            $skipped = $skipped + 1;
        }
        $sliceStart = $bytePos;
        $taken = 0;
        while ($taken < $length && $bytePos < $byteLen) {
            $byte = \ord(\substr($string, $bytePos, 1));
            $w = 1;
            if ($byte >= 240 && $byte < 248) {
                if ($bytePos + 3 < $byteLen) {
                    $w = 4;
                }
            } elseif ($byte >= 224 && $byte < 240) {
                if ($bytePos + 2 < $byteLen) {
                    $w = 3;
                }
            } elseif ($byte >= 192 && $byte < 224) {
                if ($bytePos + 1 < $byteLen) {
                    $w = 2;
                }
            }
            $bytePos = $bytePos + $w;
            $taken = $taken + 1;
        }

        return \substr($string, $sliceStart, $bytePos - $sliceStart);
    }
}
