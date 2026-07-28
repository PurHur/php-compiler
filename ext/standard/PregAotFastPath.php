<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Minimal NestedJIT-safe preg subset for thin AOT (#24115).
 */
final class PregAotFastPath
{
    /** @return int -2 unsupported, -1 error, else 0/1 */
    public static function matchCount(string $pattern, string $subject, int $offset): int
    {
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

        return self::matchClassPlus($kind, $subject, $offset);
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
        if (0 === $kind) {
            return '';
        }
        if (1 === $kind) {
            return self::replaceLiteral($pattern, $replacement, $subject, $limit);
        }

        return self::replaceClassPlus($kind, $replacement, $subject, $limit);
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
            return 1;
        }
        $i = $offset;
        while ($i + $bodyLen <= $subLen) {
            if (0 === \strncmp(\substr($subject, $i), $body, $bodyLen)) {
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
        $subLen = \strlen($subject);
        $i = $offset;
        while ($i < $subLen) {
            if (self::charInClass(\substr($subject, $i, 1), $charClass)) {
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
