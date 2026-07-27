<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_replace()/str_ireplace() for compiled JIT/AOT modules (#14779, php-in-PHP).
 *
 * SSOT: {@see VmString::strReplace()} / {@see VmString::strIreplace()}
 * php-src: ext/standard/string.c — php_str_replace, php_str_replace_in_subject
 *
 * NestedJIT user-script AOT (#23912 / peer #23871): per-char `$s[$i]` after a match can
 * sticky-read the matched byte. Prefer `\substr` slices + whole-chunk `===` (same shape as
 * ParseStrNativeJitHelper / PregJitHelper), and shrink length by arithmetic only.
 */
final class StrReplaceJitHelper
{
    private static int $lastCount = 0;

    public static function replaceArgv(string $search, string $replace, string $subject): string
    {
        if ('' === $search) {
            self::$lastCount = 0;

            return $subject;
        }
        // Prefer strlen over isset-walk: NestedJIT AOT isset can be true one past the
        // real end with a sticky matched byte (#23912 → "hell0 w0rld0").
        $searchLen = \strlen($search);
        $remLen = \strlen($subject);
        $count = 0;
        $out = '';
        $remaining = $subject;
        while ($remLen >= $searchLen) {
            $pos = self::findBounded($remaining, $remLen, $search, $searchLen);
            if ($pos < 0) {
                $out = self::concat($out, $remaining);
                self::$lastCount = $count;

                return $out;
            }
            if ($pos > 0) {
                $out = self::concat($out, \substr($remaining, 0, $pos));
            }
            $out = self::concat($out, $replace);
            $skip = $pos;
            $u = 0;
            while ($u < $searchLen) {
                ++$skip;
                ++$u;
            }
            $tailLen = 0;
            $t = $skip;
            while ($t < $remLen) {
                ++$tailLen;
                ++$t;
            }
            $remaining = \substr($remaining, $skip);
            $remLen = $tailLen;
            ++$count;
        }
        if ($remLen > 0) {
            $out = self::concat($out, $remaining);
        }
        self::$lastCount = $count;

        return $out;
    }

    public static function ireplaceArgv(string $search, string $replace, string $subject): string
    {
        $count = 0;
        $result = VmString::strIreplace($search, $replace, $subject, $count);
        self::$lastCount = $count;

        return $result;
    }

    /**
     * Find via `\substr` chunk compare — avoids per-char sticky reads (#23912).
     */
    private static function findBounded(string $hay, int $hayLen, string $needle, int $needleLen): int
    {
        if ($needleLen <= 0 || $hayLen < $needleLen) {
            return -1;
        }
        $i = 0;
        $remain = $hayLen;
        while ($remain >= $needleLen) {
            if (\substr($hay, $i, $needleLen) === $needle) {
                return $i;
            }
            ++$i;
            --$remain;
        }

        return -1;
    }

    private static function concat(string $left, string $right): string
    {
        $out = '';
        $out .= $left;
        $out .= $right;

        return $out;
    }

    public static function takeLastCount(): int
    {
        return self::$lastCount;
    }
}
