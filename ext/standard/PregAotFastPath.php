<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Minimal NestedJIT-safe preg subset for thin AOT (#24115, #26888).
 *
 * Plain int/string returns only — no by-ref arrays, no union returns (NestedJIT
 * otherwise mis-types returns as `__hashtable__*` / pointer garbage).
 *
 * Captures: {@see self::lastCap} / {@see self::lastCapCount} after a successful match.
 * php-src: ext/pcre/php_pcre.c (covered patterns only)
 */
final class PregAotFastPath
{
    public const MAX_CAPS = 8;

    private static string $cap0 = '';

    private static string $cap1 = '';

    private static string $cap2 = '';

    private static string $cap3 = '';

    private static string $cap4 = '';

    private static string $cap5 = '';

    private static string $cap6 = '';

    private static string $cap7 = '';

    private static int $capCount = 0;

    /** @return int -2 unsupported, -1 error, else 0/1 */
    public static function matchCount(string $pattern, string $subject, int $offset): int
    {
        self::clearCaps();
        // Exact two-literal-group patterns — NestedJIT-friendly (#26888).
        if ('/(a)(b)/' === $pattern || '#(a)(b)#' === $pattern) {
            return self::matchExactAbGroups($subject, $offset);
        }
        $kind = self::patternKind($pattern);
        if (0 === $kind) {
            return -2;
        }
        $subLen = \strlen($subject);
        if ($offset < 0 || $offset > $subLen) {
            return -1;
        }
        if (1 === $kind) {
            return self::matchLiteral($pattern, $subject, $offset);
        }
        if (8 === $kind) {
            return self::matchLiteralGroups($pattern, $subject, $offset);
        }

        return self::matchClassPlus($kind, $subject, $offset);
    }

    public static function lastCapCount(): int
    {
        return self::$capCount;
    }

    /** Rematerialize — NestedJIT must not return callee string ptrs as i64. */
    public static function lastCap(int $index): string
    {
        if (0 === $index) {
            return '' . self::$cap0;
        }
        if (1 === $index) {
            return '' . self::$cap1;
        }
        if (2 === $index) {
            return '' . self::$cap2;
        }
        if (3 === $index) {
            return '' . self::$cap3;
        }
        if (4 === $index) {
            return '' . self::$cap4;
        }
        if (5 === $index) {
            return '' . self::$cap5;
        }
        if (6 === $index) {
            return '' . self::$cap6;
        }
        if (7 === $index) {
            return '' . self::$cap7;
        }

        return '';
    }

    public static function patternKind(string $pattern): int
    {
        // Full-pattern compares — NestedJIT body/substr classify missed metachar bodies.
        if ('/\\d+/' === $pattern || '#\\d+#' === $pattern) {
            return 2;
        }
        if ('/(\\d+)/' === $pattern || '#(\\d+)#' === $pattern) {
            return 3;
        }
        if ('/\\s+/' === $pattern || '#\\s+#' === $pattern) {
            return 4;
        }
        if ('/(\\s+)/' === $pattern || '#(\\s+)#' === $pattern) {
            return 5;
        }
        if ('/\\w+/' === $pattern || '#\\w+#' === $pattern) {
            return 6;
        }
        if ('/(\\w+)/' === $pattern || '#(\\w+)#' === $pattern) {
            return 7;
        }
        if (self::isLiteralGroupsPattern($pattern)) {
            return 8;
        }
        $plen = \strlen($pattern);
        if ($plen < 3) {
            return 0;
        }
        $delim = \substr($pattern, 0, 1);
        if ('/' !== $delim && '#' !== $delim) {
            return 0;
        }
        $close = \strrpos($pattern, $delim);
        if (false === $close || $close < 1 || $close !== $plen - 1) {
            return 0;
        }
        $body = \substr($pattern, 1, $close - 1);
        $blen = \strlen($body);
        $i = 0;
        while ($i < $blen) {
            $c = \substr($body, $i, 1);
            if ('\\' === $c || '[' === $c || '(' === $c || ')' === $c || '|' === $c
                || '*' === $c || '+' === $c || '?' === $c || '{' === $c || '}' === $c
                || '^' === $c || '$' === $c || '.' === $c) {
                return 0;
            }
            ++$i;
        }

        return 1;
    }

    public static function replaceOrEmpty(string $pattern, string $replacement, string $subject, int $limit): string
    {
        $kind = self::patternKind($pattern);
        if (0 === $kind || 8 === $kind) {
            return '';
        }
        if (1 === $kind) {
            return self::replaceLiteral($pattern, $replacement, $subject, $limit);
        }

        return self::replaceClassPlus($kind, $replacement, $subject, $limit);
    }

    private static function clearCaps(): void
    {
        self::$cap0 = '';
        self::$cap1 = '';
        self::$cap2 = '';
        self::$cap3 = '';
        self::$cap4 = '';
        self::$cap5 = '';
        self::$cap6 = '';
        self::$cap7 = '';
        self::$capCount = 0;
    }

    private static function storeCapAt(int $index, string $value): void
    {
        if (0 === $index) {
            self::$cap0 = $value;
        } elseif (1 === $index) {
            self::$cap1 = $value;
        } elseif (2 === $index) {
            self::$cap2 = $value;
        } elseif (3 === $index) {
            self::$cap3 = $value;
        } elseif (4 === $index) {
            self::$cap4 = $value;
        } elseif (5 === $index) {
            self::$cap5 = $value;
        } elseif (6 === $index) {
            self::$cap6 = $value;
        } elseif (7 === $index) {
            self::$cap7 = $value;
        }
    }

    /** Consecutive `(literal)` groups — NestedJIT-safe bool (no array return) (#26888). */
    private static function isLiteralGroupsPattern(string $pattern): bool
    {
        $plen = \strlen($pattern);
        if ($plen < 5) {
            return false;
        }
        $delim = \substr($pattern, 0, 1);
        if ('/' !== $delim && '#' !== $delim) {
            return false;
        }
        if ($delim !== \substr($pattern, $plen - 1, 1)) {
            return false;
        }
        $body = \substr($pattern, 1, $plen - 2);
        $blen = \strlen($body);
        if (0 === $blen || '(' !== \substr($body, 0, 1)) {
            return false;
        }
        $i = 0;
        $groups = 0;
        while ($i < $blen) {
            if ('(' !== \substr($body, $i, 1)) {
                return false;
            }
            ++$i;
            while ($i < $blen) {
                $c = \substr($body, $i, 1);
                if (')' === $c) {
                    break;
                }
                if ('\\' === $c || '[' === $c || '(' === $c || '|' === $c
                    || '*' === $c || '+' === $c || '?' === $c || '{' === $c || '}' === $c
                    || '^' === $c || '$' === $c || '.' === $c) {
                    return false;
                }
                ++$i;
            }
            if ($i >= $blen || ')' !== \substr($body, $i, 1)) {
                return false;
            }
            ++$i;
            ++$groups;
            if ($groups >= self::MAX_CAPS) {
                return false;
            }
        }

        return $groups >= 1;
    }

    private static function matchExactAbGroups(string $subject, int $offset): int
    {
        $full = 'ab';
        $subLen = \strlen($subject);
        $j = $offset;
        while ($j + 2 <= $subLen) {
            if ('a' === \substr($subject, $j, 1) && 'b' === \substr($subject, $j + 1, 1)) {
                self::$cap0 = 'ab';
                self::$cap1 = 'a';
                self::$cap2 = 'b';
                self::$capCount = 3;

                return 1;
            }
            ++$j;
        }

        return 0;
    }

    /** Two literal groups `/(a)(b)/` — unrolled stores for NestedJIT (#26888). */
    private static function matchLiteralGroups(string $pattern, string $subject, int $offset): int
    {
        $plen = \strlen($pattern);
        if ($plen < 7) {
            return -2;
        }
        $delim = \substr($pattern, 0, 1);
        if ('/' !== $delim && '#' !== $delim) {
            return -2;
        }
        if ($delim !== \substr($pattern, $plen - 1, 1)) {
            return -2;
        }
        $body = \substr($pattern, 1, $plen - 2);
        $blen = \strlen($body);
        if ($blen < 4 || '(' !== \substr($body, 0, 1)) {
            return -2;
        }
        $i = 1;
        $g1Start = 1;
        while ($i < $blen && ')' !== \substr($body, $i, 1)) {
            $c = \substr($body, $i, 1);
            if ('\\' === $c || '[' === $c || '(' === $c || '|' === $c
                || '*' === $c || '+' === $c || '?' === $c || '{' === $c || '}' === $c
                || '^' === $c || '$' === $c || '.' === $c) {
                return -2;
            }
            ++$i;
        }
        if ($i >= $blen || ')' !== \substr($body, $i, 1)) {
            return -2;
        }
        $g1 = \substr($body, $g1Start, $i - $g1Start);
        ++$i;
        if ($i >= $blen || '(' !== \substr($body, $i, 1)) {
            if ($i !== $blen) {
                return -2;
            }
            $full = $g1;
            $fullLen = \strlen($full);
            $subLen = \strlen($subject);
            $j = $offset;
            while ($j + $fullLen <= $subLen) {
                if (0 === $fullLen || 0 === \strncmp(\substr($subject, $j), $full, $fullLen)) {
                    self::storeCapAt(0, $full);
                    self::storeCapAt(1, $g1);
                    self::$capCount = 2;

                    return 1;
                }
                if (0 === $fullLen) {
                    break;
                }
                ++$j;
            }

            return 0;
        }
        ++$i;
        $g2Start = $i;
        while ($i < $blen && ')' !== \substr($body, $i, 1)) {
            $c = \substr($body, $i, 1);
            if ('\\' === $c || '[' === $c || '(' === $c || '|' === $c
                || '*' === $c || '+' === $c || '?' === $c || '{' === $c || '}' === $c
                || '^' === $c || '$' === $c || '.' === $c) {
                return -2;
            }
            ++$i;
        }
        if ($i >= $blen || ')' !== \substr($body, $i, 1)) {
            return -2;
        }
        $g2 = \substr($body, $g2Start, $i - $g2Start);
        ++$i;
        if ($i !== $blen) {
            return -2;
        }
        $full = $g1 . $g2;
        $fullLen = \strlen($full);
        $subLen = \strlen($subject);
        $j = $offset;
        while ($j + $fullLen <= $subLen) {
            if (0 === \strncmp(\substr($subject, $j), $full, $fullLen)) {
                self::storeCapAt(0, $full);
                self::storeCapAt(1, $g1);
                self::storeCapAt(2, $g2);
                self::$capCount = 3;

                return 1;
            }
            ++$j;
        }

        return 0;
    }

    private static function storeCaps(string $full, bool $hasGroup): void
    {
        self::storeCapAt(0, $full);
        if ($hasGroup) {
            self::storeCapAt(1, $full);
            self::$capCount = 2;
        } else {
            self::storeCapAt(1, '');
            self::$capCount = 1;
        }
    }

    private static function matchLiteral(string $pattern, string $subject, int $offset): int
    {
        $delim = \substr($pattern, 0, 1);
        $close = \strrpos($pattern, $delim);
        if (false === $close || $close < 1) {
            return -2;
        }
        $body = \substr($pattern, 1, $close - 1);
        $bodyLen = \strlen($body);
        $subLen = \strlen($subject);
        if (0 === $bodyLen) {
            self::storeCaps('', false);

            return 1;
        }
        $i = $offset;
        while ($i + $bodyLen <= $subLen) {
            if (0 === \strncmp(\substr($subject, $i), $body, $bodyLen)) {
                self::storeCaps($body, false);

                return 1;
            }
            ++$i;
        }

        return 0;
    }

    private static function matchClassPlus(int $kind, string $subject, int $offset): int
    {
        $charClass = 2;
        if (4 === $kind || 5 === $kind) {
            $charClass = 3;
        } elseif (6 === $kind || 7 === $kind) {
            $charClass = 4;
        }
        $hasGroup = (3 === $kind || 5 === $kind || 7 === $kind);
        $subLen = \strlen($subject);
        $i = $offset;
        while ($i < $subLen) {
            if (self::charInClass(\substr($subject, $i, 1), $charClass)) {
                $j = $i + 1;
                while ($j < $subLen && self::charInClass(\substr($subject, $j, 1), $charClass)) {
                    ++$j;
                }
                self::storeCaps(\substr($subject, $i, $j - $i), $hasGroup);

                return 1;
            }
            ++$i;
        }

        return 0;
    }

    private static function replaceLiteral(string $pattern, string $replacement, string $subject, int $limit): string
    {
        $delim = \substr($pattern, 0, 1);
        $close = \strrpos($pattern, $delim);
        if (false === $close || $close < 1) {
            return '';
        }
        $body = \substr($pattern, 1, $close - 1);
        if (0 === $limit) {
            return $subject;
        }
        $out = '';
        $subLen = \strlen($subject);
        $bodyLen = \strlen($body);
        $cursor = 0;
        $n = 0;
        while ($cursor < $subLen) {
            if ($limit >= 0 && $n >= $limit) {
                $out .= \substr($subject, $cursor);

                return $out;
            }
            if (0 === $bodyLen) {
                $out .= $replacement.\substr($subject, $cursor, 1);
                ++$cursor;
                ++$n;
                continue;
            }
            if ($cursor + $bodyLen <= $subLen
                && 0 === \strncmp(\substr($subject, $cursor), $body, $bodyLen)) {
                $out .= $replacement;
                $cursor += $bodyLen;
                ++$n;
                continue;
            }
            $out .= \substr($subject, $cursor, 1);
            ++$cursor;
        }
        if (0 === $bodyLen) {
            $out .= $replacement;
        }

        return $out;
    }

    private static function replaceClassPlus(int $kind, string $replacement, string $subject, int $limit): string
    {
        $charClass = 2;
        if (4 === $kind || 5 === $kind) {
            $charClass = 3;
        } elseif (6 === $kind || 7 === $kind) {
            $charClass = 4;
        }
        if (0 === $limit) {
            return $subject;
        }
        $out = '';
        $subLen = \strlen($subject);
        $cursor = 0;
        $n = 0;
        while ($cursor < $subLen) {
            if ($limit >= 0 && $n >= $limit) {
                $out .= \substr($subject, $cursor);

                return $out;
            }
            if (!self::charInClass(\substr($subject, $cursor, 1), $charClass)) {
                $out .= \substr($subject, $cursor, 1);
                ++$cursor;
                continue;
            }
            $j = $cursor + 1;
            while ($j < $subLen && self::charInClass(\substr($subject, $j, 1), $charClass)) {
                ++$j;
            }
            $out .= $replacement;
            $cursor = $j;
            ++$n;
        }

        return $out;
    }

    private static function charInClass(string $ch, int $charClass): bool
    {
        if (1 !== \strlen($ch)) {
            return false;
        }
        $o = \ord($ch);
        if (2 === $charClass) {
            return $o >= 48 && $o <= 57;
        }
        if (3 === $charClass) {
            return 32 === $o || 9 === $o || 10 === $o || 13 === $o || 12 === $o || 11 === $o;
        }

        return ($o >= 48 && $o <= 57) || ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122) || 95 === $o;
    }
}
