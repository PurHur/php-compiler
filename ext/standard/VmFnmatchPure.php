<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP POSIX fnmatch (BSD fnmatch.c port; #8016, phase 2 of #7756).
 *
 * php-src: win32/fnmatch.c — FNM_PATHNAME, FNM_NOESCAPE, FNM_PERIOD, FNM_CASEFOLD
 */
final class VmFnmatchPure
{
    private const FNM_NOMATCH = 1;

    public static function available(): bool
    {
        return true;
    }

    public static function match(string $pattern, string $string, int $flags = 0): bool
    {
        return 0 === self::fnmatch($pattern, 0, $string, 0, $flags, 0);
    }

    private static function fnmatch(string $pattern, int $p, string $string, int $s, int $flags, int $stringStart): int
    {
        while (true) {
            $c = self::byteAt($pattern, $p);
            ++$p;
            if ('' === $c) {
                return '' === self::byteAt($string, $s) ? 0 : self::FNM_NOMATCH;
            }

            if ('?' === $c) {
                if ('' === self::byteAt($string, $s)) {
                    return self::FNM_NOMATCH;
                }
                if ('/' === self::byteAt($string, $s) && 0 !== ($flags & VmFnmatch::FNM_PATHNAME)) {
                    return self::FNM_NOMATCH;
                }
                if (
                    '.' === self::byteAt($string, $s)
                    && 0 !== ($flags & VmFnmatch::FNM_PERIOD)
                    && ($s === $stringStart || (0 !== ($flags & VmFnmatch::FNM_PATHNAME) && '/' === self::byteAt($string, $s - 1)))
                ) {
                    return self::FNM_NOMATCH;
                }
                ++$s;

                continue;
            }

            if ('*' === $c) {
                $next = self::byteAt($pattern, $p);
                while ('*' === $next) {
                    $next = self::byteAt($pattern, ++$p);
                }

                if (
                    '.' === self::byteAt($string, $s)
                    && 0 !== ($flags & VmFnmatch::FNM_PERIOD)
                    && ($s === $stringStart || (0 !== ($flags & VmFnmatch::FNM_PATHNAME) && '/' === self::byteAt($string, $s - 1)))
                ) {
                    return self::FNM_NOMATCH;
                }

                if ('' === $next) {
                    if (0 !== ($flags & VmFnmatch::FNM_PATHNAME)) {
                        return false === self::strposFrom($string, '/', $s) ? 0 : self::FNM_NOMATCH;
                    }

                    return 0;
                }

                if ('/' === $next && 0 !== ($flags & VmFnmatch::FNM_PATHNAME)) {
                    $slash = self::strposFrom($string, '/', $s);
                    if (false === $slash) {
                        return self::FNM_NOMATCH;
                    }
                    $s = $slash;

                    continue;
                }

                while ('' !== ($test = self::byteAt($string, $s))) {
                    if (0 === self::fnmatch($pattern, $p, $string, $s, $flags & ~VmFnmatch::FNM_PERIOD, $s)) {
                        return 0;
                    }
                    if ('/' === $test && 0 !== ($flags & VmFnmatch::FNM_PATHNAME)) {
                        break;
                    }
                    ++$s;
                }

                return self::FNM_NOMATCH;
            }

            if ('[' === $c) {
                if ('' === self::byteAt($string, $s)) {
                    return self::FNM_NOMATCH;
                }
                if ('/' === self::byteAt($string, $s) && 0 !== ($flags & VmFnmatch::FNM_PATHNAME)) {
                    return self::FNM_NOMATCH;
                }
                $rangeEnd = self::rangeMatch($pattern, $p, self::byteAt($string, $s), $flags);
                if (null === $rangeEnd) {
                    return self::FNM_NOMATCH;
                }
                $p = $rangeEnd;
                ++$s;

                continue;
            }

            if ('\\' === $c && 0 === ($flags & VmFnmatch::FNM_NOESCAPE)) {
                $c = self::byteAt($pattern, $p);
                if ('' === $c) {
                    $c = '\\';
                    --$p;
                } else {
                    ++$p;
                }
            }

            if (!self::charEqual($c, self::byteAt($string, $s), $flags)) {
                return self::FNM_NOMATCH;
            }
            ++$s;
        }
    }

    /**
     * @return int|null new pattern offset after ']', or null on mismatch
     */
    private static function rangeMatch(string $pattern, int $p, string $test, int $flags): ?int
    {
        $negate = false;
        $first = self::byteAt($pattern, $p);
        if ('!' === $first || '^' === $first) {
            $negate = true;
            ++$p;
        }

        if (0 !== ($flags & VmFnmatch::FNM_CASEFOLD)) {
            $test = self::tolowerByte($test);
        }

        $ok = false;
        while (true) {
            $c = self::byteAt($pattern, $p);
            ++$p;
            if (']' === $c) {
                break;
            }
            if ('' === $c) {
                return null;
            }

            if ('\\' === $c && 0 === ($flags & VmFnmatch::FNM_NOESCAPE)) {
                $c = self::byteAt($pattern, $p);
                if ('' === $c) {
                    return null;
                }
                ++$p;
            }

            if (0 !== ($flags & VmFnmatch::FNM_CASEFOLD)) {
                $c = self::tolowerByte($c);
            }

            $c2 = self::byteAt($pattern, $p);
            if ('-' === $c2) {
                $c2Next = self::byteAt($pattern, $p + 1);
                if ('' !== $c2Next && ']' !== $c2Next) {
                    $p += 2;
                    $c2 = $c2Next;
                    if ('\\' === $c2 && 0 === ($flags & VmFnmatch::FNM_NOESCAPE)) {
                        $c2 = self::byteAt($pattern, $p);
                        if ('' === $c2) {
                            return null;
                        }
                        ++$p;
                    }
                    if (0 !== ($flags & VmFnmatch::FNM_CASEFOLD)) {
                        $c2 = self::tolowerByte($c2);
                    }
                    if (self::ord($c) <= self::ord($test) && self::ord($test) <= self::ord($c2)) {
                        $ok = true;
                    }

                    continue;
                }
            }

            if ($c === $test) {
                $ok = true;
            }
        }

        return $ok !== $negate ? $p : null;
    }

    private static function charEqual(string $c, string $s, int $flags): bool
    {
        if ('' === $s) {
            return false;
        }
        if ($c === $s) {
            return true;
        }
        if (0 !== ($flags & VmFnmatch::FNM_CASEFOLD)) {
            return self::tolowerByte($c) === self::tolowerByte($s);
        }

        return false;
    }

    private static function byteAt(string $str, int $offset): string
    {
        return $str[$offset] ?? '';
    }

    private static function ord(string $byte): int
    {
        return \ord($byte);
    }

    private static function tolowerByte(string $byte): string
    {
        $o = \ord($byte);
        if ($o >= 65 && $o <= 90) {
            return \chr($o + 32);
        }

        return $byte;
    }

    /**
     * @return int|false
     */
    private static function strposFrom(string $haystack, string $needle, int $offset)
    {
        $slice = \substr($haystack, $offset);
        if ('' === $slice) {
            return false;
        }
        $pos = \strpos($slice, $needle);

        return false === $pos ? false : $offset + $pos;
    }
}
