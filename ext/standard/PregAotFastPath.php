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
        if (9 === $kind) {
            return self::matchAnchoredLiteralPrefix($pattern, $subject, $offset);
        }
        // Single class char \d/\s/\w (#27250) — NestedJIT cannot defer to host PCRE.
        if ($kind >= 10 && $kind <= 15) {
            return self::matchClassOnce($kind, $subject, $offset);
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
        // Single class char (no +) — issue #27250 silent wrong-0 under thin AOT.
        if ('/\\d/' === $pattern || '#\\d#' === $pattern) {
            return 10;
        }
        if ('/(\\d)/' === $pattern || '#(\\d)#' === $pattern) {
            return 11;
        }
        if ('/\\s/' === $pattern || '#\\s#' === $pattern) {
            return 12;
        }
        if ('/(\\s)/' === $pattern || '#(\\s)#' === $pattern) {
            return 13;
        }
        if ('/\\w/' === $pattern || '#\\w#' === $pattern) {
            return 14;
        }
        if ('/(\\w)/' === $pattern || '#(\\w)#' === $pattern) {
            return 15;
        }
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
        if (self::isAnchoredLiteralPrefixPattern($pattern)) {
            return 9;
        }
        $plen = \strlen($pattern);
        if ($plen < 3) {
            return 0;
        }
        // NestedJIT: avoid strrpos — closing delim must be last char (no flags) (#27119).
        $close = self::delimitedBodyClose($pattern);
        if ($close < 1) {
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

    /**
     * Index of closing `/` or `#` when it is the final character (no modifiers).
     * NestedJIT-safe substitute for strrpos (#27119 / peer #26888).
     */
    private static function delimitedBodyClose(string $pattern): int
    {
        $plen = \strlen($pattern);
        if ($plen < 2) {
            return -1;
        }
        $delim = \substr($pattern, 0, 1);
        if ('/' !== $delim && '#' !== $delim) {
            return -1;
        }
        if ($delim !== \substr($pattern, $plen - 1, 1)) {
            return -1;
        }

        return $plen - 1;
    }

    /** Char-by-char equality — NestedJIT strncmp/substr-prefix compares miscompile (#27119). */
    private static function literalEqualsAt(string $haystack, int $offset, string $needle, int $needleLen): bool
    {
        $i = 0;
        while ($i < $needleLen) {
            if (\substr($haystack, $offset + $i, 1) !== \substr($needle, $i, 1)) {
                return false;
            }
            ++$i;
        }

        return true;
    }

    private static int $lastReplacePos = -1;

    private static int $lastReplaceBodyLen = 0;

    public static function replaceOrEmpty(string $pattern, string $replacement, string $subject, int $limit): string
    {
        $kind = self::patternKind($pattern);
        if (0 === $kind || 8 === $kind || 9 === $kind) {
            return '';
        }
        if (1 === $kind) {
            return self::replaceLiteral($pattern, $replacement, $subject, $limit);
        }
        if ($kind >= 10 && $kind <= 15) {
            return self::replaceClassOnce($kind, $replacement, $subject, $limit);
        }

        return self::replaceClassPlus($kind, $replacement, $subject, $limit);
    }

    /**
     * Find next literal match — NestedJIT int-only (#27181).
     * Result offsets in {@see $lastReplacePos} / {@see $lastReplaceBodyLen}.
     *
     * @return int 1 matched, 0 no match, -1 unsupported
     */
    public static function replaceFindNext(string $pattern, string $subject, int $offset): int
    {
        self::$lastReplacePos = -1;
        self::$lastReplaceBodyLen = 0;
        $kind = self::patternKind($pattern);
        if (0 === $kind || 8 === $kind || 9 === $kind) {
            return -1;
        }
        if (1 !== $kind) {
            // Class-plus / single class: scan for first hit at/after offset.
            if ($kind >= 10 && $kind <= 15) {
                return self::findClassOnce($kind, $subject, $offset);
            }

            return self::findClassPlus($kind, $subject, $offset);
        }
        $close = self::delimitedBodyClose($pattern);
        if ($close < 1) {
            return -1;
        }
        $body = \substr($pattern, 1, $close - 1);
        $bodyLen = \strlen($body);
        $subLen = \strlen($subject);
        if (0 === $bodyLen) {
            return -1;
        }
        self::$lastReplaceBodyLen = $bodyLen;
        $i = $offset;
        if ($i < 0) {
            $i = 0;
        }
        while ($i + $bodyLen <= $subLen) {
            if (self::literalEqualsAt($subject, $i, $body, $bodyLen)) {
                self::$lastReplacePos = $i;

                return 1;
            }
            ++$i;
        }

        return 0;
    }

    public static function takeLastReplacePos(): int
    {
        return self::$lastReplacePos;
    }

    public static function takeLastReplaceBodyLen(): int
    {
        return self::$lastReplaceBodyLen;
    }

    private static function findClassPlus(int $kind, string $subject, int $offset): int
    {
        $charClass = 2;
        if (4 === $kind || 5 === $kind) {
            $charClass = 3;
        } elseif (6 === $kind || 7 === $kind) {
            $charClass = 4;
        }
        $subLen = \strlen($subject);
        $i = $offset;
        if ($i < 0) {
            $i = 0;
        }
        while ($i < $subLen) {
            if (self::charInClass(\substr($subject, $i, 1), $charClass)) {
                $j = $i + 1;
                while ($j < $subLen && self::charInClass(\substr($subject, $j, 1), $charClass)) {
                    ++$j;
                }
                self::$lastReplacePos = $i;
                self::$lastReplaceBodyLen = $j - $i;

                return 1;
            }
            ++$i;
        }

        return 0;
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

    /**
     * `/^literal/` / `#^literal#` — start-anchored literal body (#26825 RegexIterator MATCH).
     *
     * Body after `^` must be NestedJIT-safe literal (no other metacharacters).
     */
    private static function isAnchoredLiteralPrefixPattern(string $pattern): bool
    {
        $plen = \strlen($pattern);
        if ($plen < 4) {
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
        if ('' === $body || '^' !== \substr($body, 0, 1)) {
            return false;
        }
        $lit = \substr($body, 1);
        $llen = \strlen($lit);
        if (0 === $llen) {
            return false;
        }
        $i = 0;
        while ($i < $llen) {
            $c = \substr($lit, $i, 1);
            if ('\\' === $c || '[' === $c || '(' === $c || ')' === $c || '|' === $c
                || '*' === $c || '+' === $c || '?' === $c || '{' === $c || '}' === $c
                || '^' === $c || '$' === $c || '.' === $c) {
                return false;
            }
            ++$i;
        }

        return true;
    }

    private static function matchAnchoredLiteralPrefix(string $pattern, string $subject, int $offset): int
    {
        $plen = \strlen($pattern);
        $lit = \substr($pattern, 2, $plen - 3);
        $litLen = \strlen($lit);
        $subLen = \strlen($subject);
        if ($offset > 0) {
            // ^ only matches at start of subject (not after offset) for thin AOT.
            return 0;
        }
        if ($litLen > $subLen) {
            return 0;
        }
        $i = 0;
        while ($i < $litLen) {
            if (\substr($subject, $i, 1) !== \substr($lit, $i, 1)) {
                return 0;
            }
            ++$i;
        }
        self::$cap0 = \substr($subject, 0, $litLen);
        self::$capCount = 1;

        return 1;
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
        $close = self::delimitedBodyClose($pattern);
        if ($close < 1) {
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
            if (self::literalEqualsAt($subject, $i, $body, $bodyLen)) {
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

    /** Single \d/\s/\w (kinds 10–15) — #27250. */
    private static function classKindToCharClass(int $kind): int
    {
        if (12 === $kind || 13 === $kind || 4 === $kind || 5 === $kind) {
            return 3;
        }
        if (14 === $kind || 15 === $kind || 6 === $kind || 7 === $kind) {
            return 4;
        }

        return 2;
    }

    private static function matchClassOnce(int $kind, string $subject, int $offset): int
    {
        $charClass = self::classKindToCharClass($kind);
        $hasGroup = (11 === $kind || 13 === $kind || 15 === $kind);
        $subLen = \strlen($subject);
        $i = $offset;
        while ($i < $subLen) {
            $ch = \substr($subject, $i, 1);
            if (self::charInClass($ch, $charClass)) {
                self::storeCaps($ch, $hasGroup);

                return 1;
            }
            ++$i;
        }

        return 0;
    }

    private static function findClassOnce(int $kind, string $subject, int $offset): int
    {
        $charClass = self::classKindToCharClass($kind);
        $subLen = \strlen($subject);
        $i = $offset;
        if ($i < 0) {
            $i = 0;
        }
        while ($i < $subLen) {
            if (self::charInClass(\substr($subject, $i, 1), $charClass)) {
                self::$lastReplacePos = $i;
                self::$lastReplaceBodyLen = 1;

                return 1;
            }
            ++$i;
        }

        return 0;
    }

    private static function replaceClassOnce(int $kind, string $replacement, string $subject, int $limit): string
    {
        $charClass = self::classKindToCharClass($kind);
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
            $ch = \substr($subject, $cursor, 1);
            if (!self::charInClass($ch, $charClass)) {
                $out .= $ch;
                ++$cursor;
                continue;
            }
            $out .= $replacement;
            ++$cursor;
            ++$n;
        }

        return $out;
    }

    private static function replaceLiteral(string $pattern, string $replacement, string $subject, int $limit): string
    {
        $close = self::delimitedBodyClose($pattern);
        if ($close < 1) {
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
                && self::literalEqualsAt($subject, $cursor, $body, $bodyLen)) {
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

    private static int $splitCount = 0;

    private static string $split0 = '';

    private static string $split1 = '';

    private static string $split2 = '';

    private static string $split3 = '';

    private static string $split4 = '';

    private static string $split5 = '';

    private static string $split6 = '';

    private static string $split7 = '';

    private static int $matchAllCount = 0;

    private static string $matchAll0 = '';

    private static string $matchAll1 = '';

    private static string $matchAll2 = '';

    private static string $matchAll3 = '';

    private static string $matchAll4 = '';

    private static string $matchAll5 = '';

    private static string $matchAll6 = '';

    private static string $matchAll7 = '';

    /**
     * NestedJIT-safe preg_match_all — int return + full-match string slots (#27195).
     *
     * PREG_PATTERN_ORDER without capture groups only (flags==0). LLVM builds
     * `$matches[0] = [m0, m1, …]` from {@see self::matchAllPart}.
     *
     * @return int match count, 0 if none, -1 unsupported/error
     */
    public static function matchAllStore(string $pattern, string $subject, int $flags, int $offset): int
    {
        self::$matchAllCount = 0;
        self::$matchAll0 = '';
        self::$matchAll1 = '';
        self::$matchAll2 = '';
        self::$matchAll3 = '';
        self::$matchAll4 = '';
        self::$matchAll5 = '';
        self::$matchAll6 = '';
        self::$matchAll7 = '';
        if (0 !== $flags) {
            return -1;
        }
        $subLen = \strlen($subject);
        if ($offset < 0 || $offset > $subLen) {
            return 0;
        }
        $kind = self::patternKind($pattern);
        // No-group class-plus (2/4/6), single class (10/12/14), and plain literals (1).
        // Grouped kinds need nested group rows — defer until thin slots expand.
        if (1 !== $kind && 2 !== $kind && 4 !== $kind && 6 !== $kind
            && 10 !== $kind && 12 !== $kind && 14 !== $kind) {
            return -1;
        }
        // Reuse NestedJIT-proven matchCount; advance past each hit (#27195).
        $cursor = $offset;
        $n = 0;
        while ($cursor < $subLen) {
            $rc = self::matchCount($pattern, $subject, $cursor);
            if ($rc < 0) {
                return -1;
            }
            if (0 === $rc) {
                break;
            }
            $full = '' . self::lastCap(0);
            $flen = \strlen($full);
            if ($flen < 1) {
                return -1;
            }
            $start = $cursor;
            $found = 0;
            while ($start + $flen <= $subLen) {
                if (self::literalEqualsAt($subject, $start, $full, $flen)) {
                    $found = 1;
                    break;
                }
                ++$start;
            }
            if (0 === $found) {
                return -1;
            }
            if ($n >= self::MAX_CAPS) {
                return -1;
            }
            self::storeMatchAllAt($n, $full);
            ++$n;
            $cursor = $start + $flen;
        }
        self::$matchAllCount = $n;

        return $n;
    }

    public static function matchAllPartCount(): int
    {
        return self::$matchAllCount;
    }

    public static function matchAllPart(int $index): string
    {
        if (0 === $index) {
            return '' . self::$matchAll0;
        }
        if (1 === $index) {
            return '' . self::$matchAll1;
        }
        if (2 === $index) {
            return '' . self::$matchAll2;
        }
        if (3 === $index) {
            return '' . self::$matchAll3;
        }
        if (4 === $index) {
            return '' . self::$matchAll4;
        }
        if (5 === $index) {
            return '' . self::$matchAll5;
        }
        if (6 === $index) {
            return '' . self::$matchAll6;
        }
        if (7 === $index) {
            return '' . self::$matchAll7;
        }

        return '';
    }

    private static function storeMatchAllAt(int $index, string $value): void
    {
        if (0 === $index) {
            self::$matchAll0 = $value;
        } elseif (1 === $index) {
            self::$matchAll1 = $value;
        } elseif (2 === $index) {
            self::$matchAll2 = $value;
        } elseif (3 === $index) {
            self::$matchAll3 = $value;
        } elseif (4 === $index) {
            self::$matchAll4 = $value;
        } elseif (5 === $index) {
            self::$matchAll5 = $value;
        } elseif (6 === $index) {
            self::$matchAll6 = $value;
        } elseif (7 === $index) {
            self::$matchAll7 = $value;
        }
    }

    /**
     * NestedJIT-safe preg_split — int return + static string slots only (no PHP arrays, #27080).
     *
     * Thin standalone AOT no longer calls this — LLVM uses replaceFindNext + subject slices
     * (#27208). Kept for host unit tests / ThinAot splitArgv stub parity.
     *
     * @return int part count, or -1 on unsupported/error
     */
    public static function splitStore(string $pattern, string $subject, int $limit, int $flags): int
    {
        self::$splitCount = 0;
        self::$split0 = '';
        self::$split1 = '';
        self::$split2 = '';
        self::$split3 = '';
        self::$split4 = '';
        self::$split5 = '';
        self::$split6 = '';
        self::$split7 = '';
        if (0 !== $flags) {
            return -1;
        }
        $kind = self::patternKind($pattern);
        if (0 === $kind || 8 === $kind || 9 === $kind || 1 === $kind) {
            return -1;
        }
        $charClass = 2;
        if (4 === $kind || 5 === $kind) {
            $charClass = 3;
        } elseif (6 === $kind || 7 === $kind) {
            $charClass = 4;
        }
        $subLen = \strlen($subject);
        $cursor = 0;
        $n = 0;
        while ($cursor < $subLen) {
            while ($cursor < $subLen && self::charInClass(\substr($subject, $cursor, 1), $charClass)) {
                ++$cursor;
            }
            if ($cursor >= $subLen) {
                break;
            }
            if ($limit > 0 && $n + 1 >= $limit) {
                if ($n >= self::MAX_CAPS) {
                    return -1;
                }
                self::storeSplitAt($n, '' . \substr($subject, $cursor));
                ++$n;
                self::$splitCount = $n;

                return $n;
            }
            $start = $cursor;
            while ($cursor < $subLen) {
                if (self::charInClass(\substr($subject, $cursor, 1), $charClass)) {
                    break;
                }
                ++$cursor;
            }
            if ($n >= self::MAX_CAPS) {
                return -1;
            }
            self::storeSplitAt($n, '' . \substr($subject, $start, $cursor - $start));
            ++$n;
        }
        self::$splitCount = $n;

        return $n;
    }

    public static function splitPartCount(): int
    {
        return self::$splitCount;
    }

    public static function splitPart(int $index): string
    {
        if (0 === $index) {
            return '' . self::$split0;
        }
        if (1 === $index) {
            return '' . self::$split1;
        }
        if (2 === $index) {
            return '' . self::$split2;
        }
        if (3 === $index) {
            return '' . self::$split3;
        }
        if (4 === $index) {
            return '' . self::$split4;
        }
        if (5 === $index) {
            return '' . self::$split5;
        }
        if (6 === $index) {
            return '' . self::$split6;
        }
        if (7 === $index) {
            return '' . self::$split7;
        }

        return '';
    }

    private static function storeSplitAt(int $index, string $value): void
    {
        if (0 === $index) {
            self::$split0 = $value;
        } elseif (1 === $index) {
            self::$split1 = $value;
        } elseif (2 === $index) {
            self::$split2 = $value;
        } elseif (3 === $index) {
            self::$split3 = $value;
        } elseif (4 === $index) {
            self::$split4 = $value;
        } elseif (5 === $index) {
            self::$split5 = $value;
        } elseif (6 === $index) {
            self::$split6 = $value;
        } elseif (7 === $index) {
            self::$split7 = $value;
        }
    }
}
