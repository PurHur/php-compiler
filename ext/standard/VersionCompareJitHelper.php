<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for __compiler_version_compare (#9813, #26866, php-in-PHP).
 *
 * NestedJIT thin-AOT constraints (#26866; peer #26884 hexdec):
 * - Do not call {@see VmInfo}.
 * - Avoid ctype_* / explode / `$str[$i]` / strlen / array returns / static props.
 * - **No canonicalizeVersion** — NestedJIT SEGVs on the canonicalize recursion under thin AOT.
 *   Literal args fold via {@see \PHPCompiler\ext\standard\JitInfo} + VmInfo at compile time.
 *   Runtime digit.dot forms still compare correctly without canonicalize.
 * - **ABI return is unsigned-coded** (0=lt, 1=eq, 2=gt); bridge subtracts 1.
 * - Bridge must pass `__string__*` straight through (see StringVersionCompare).
 *
 * php-src: ext/standard/versioning.c — php_version_compare
 */
final class VersionCompareJitHelper
{
    /**
     * @return int 0=less, 1=equal, 2=greater (bridge maps to -1/0/1) (#26866)
     */
    public static function compare(string $ver1, string $ver2): int
    {
        return self::phpVersionCompare($ver1, $ver2) + 1;
    }

    /** @return int -1, 0, or 1 */
    private static function phpVersionCompare(string $origVer1, string $origVer2): int
    {
        if ('' === $origVer1 || '' === $origVer2) {
            if ('' === $origVer1 && '' === $origVer2) {
                return 0;
            }

            return '' !== $origVer1 ? 1 : -1;
        }

        // Skip canonicalize under NestedJIT (#26866 SEGV). `#` prefix forms pass through.
        return self::compareSegments($origVer1, $origVer2);
    }

    private static function compareSegments(string $p1, string $p2): int
    {
        if ('' === $p1 || '' === $p2) {
            if ('' === $p1 && '' === $p2) {
                return 0;
            }
            if ('' === $p1) {
                return ('' !== $p2 && self::isDigitChar(\substr($p2, 0, 1)))
                    ? -1
                    : self::phpVersionCompare('#N#', $p2);
            }

            return ('' !== $p1 && self::isDigitChar(\substr($p1, 0, 1)))
                ? 1
                : self::phpVersionCompare($p1, '#N#');
        }

        $dot1 = self::findDot($p1, 0, self::strlenRec($p1, 0));
        $dot2 = self::findDot($p2, 0, self::strlenRec($p2, 0));
        $seg1 = $dot1 < 0 ? $p1 : \substr($p1, 0, $dot1);
        $seg2 = $dot2 < 0 ? $p2 : \substr($p2, 0, $dot2);
        $rest1 = $dot1 < 0 ? '' : \substr($p1, $dot1 + 1);
        $rest2 = $dot2 < 0 ? '' : \substr($p2, $dot2 + 1);
        $has1 = $dot1 >= 0;
        $has2 = $dot2 >= 0;

        $digit1 = self::isAllDigits($seg1);
        $digit2 = self::isAllDigits($seg2);
        if ($digit1 && $digit2) {
            $compare = self::cmpDigitStrings($seg1, $seg2);
        } elseif (!$digit1 && !$digit2) {
            $compare = self::cmpInt(self::specialFormOrder($seg1), self::specialFormOrder($seg2));
        } elseif ($digit1) {
            $compare = self::cmpInt(self::specialFormOrder('#N#'), self::specialFormOrder($seg2));
        } else {
            $compare = self::cmpInt(self::specialFormOrder($seg1), self::specialFormOrder('#N#'));
        }
        if (0 !== $compare) {
            return $compare;
        }
        if (!$has1 && !$has2) {
            return 0;
        }
        if (!$has1) {
            return self::compareSegments('', $rest2);
        }
        if (!$has2) {
            return self::compareSegments($rest1, '');
        }

        return self::compareSegments($rest1, $rest2);
    }

    private static function strlenRec(string $s, int $i): int
    {
        if ('' === \substr($s, $i, 1)) {
            return $i;
        }

        return self::strlenRec($s, $i + 1);
    }

    private static function findDot(string $p, int $i, int $len): int
    {
        if ($i >= $len) {
            return -1;
        }
        if ('.' === \substr($p, $i, 1)) {
            return $i;
        }

        return self::findDot($p, $i + 1, $len);
    }

    private static function isAllDigits(string $s): bool
    {
        $len = self::strlenRec($s, 0);
        if (0 === $len) {
            return false;
        }

        return self::isAllDigitsRec($s, 0, $len);
    }

    private static function isAllDigitsRec(string $s, int $i, int $len): bool
    {
        if ($i >= $len) {
            return true;
        }
        if (!self::isDigitChar(\substr($s, $i, 1))) {
            return false;
        }

        return self::isAllDigitsRec($s, $i + 1, $len);
    }

    private static function isDigitChar(string $ch): bool
    {
        return '0' === $ch || '1' === $ch || '2' === $ch || '3' === $ch || '4' === $ch
            || '5' === $ch || '6' === $ch || '7' === $ch || '8' === $ch || '9' === $ch;
    }

    private static function cmpDigitStrings(string $a, string $b): int
    {
        $la = self::strlenRec($a, 0);
        $lb = self::strlenRec($b, 0);
        $a0 = self::skipLeadingZeros($a, 0, $la);
        $b0 = self::skipLeadingZeros($b, 0, $lb);
        $la2 = $la - $a0;
        $lb2 = $lb - $b0;
        if ($la2 < $lb2) {
            return -1;
        }
        if ($la2 > $lb2) {
            return 1;
        }

        return self::cmpSameLenDigits($a, $a0, $b, $b0, $la2);
    }

    private static function skipLeadingZeros(string $s, int $i, int $len): int
    {
        if ($i + 1 >= $len) {
            return $i;
        }
        if ('0' !== \substr($s, $i, 1)) {
            return $i;
        }

        return self::skipLeadingZeros($s, $i + 1, $len);
    }

    private static function cmpSameLenDigits(string $a, int $ai, string $b, int $bi, int $n): int
    {
        if (0 === $n) {
            return 0;
        }
        $ca = \substr($a, $ai, 1);
        $cb = \substr($b, $bi, 1);
        if ($ca === $cb) {
            return self::cmpSameLenDigits($a, $ai + 1, $b, $bi + 1, $n - 1);
        }
        if ($ca < $cb) {
            return -1;
        }

        return 1;
    }

    private static function specialFormOrder(string $form): int
    {
        if (self::startsWith($form, 'dev')) {
            return 0;
        }
        if (self::startsWith($form, 'alpha') || self::startsWith($form, 'a')) {
            return 1;
        }
        if (self::startsWith($form, 'beta') || self::startsWith($form, 'b')) {
            return 2;
        }
        if (self::startsWith($form, 'RC') || self::startsWith($form, 'rc')) {
            return 3;
        }
        if (self::startsWith($form, '#')) {
            return 4;
        }
        if (self::startsWith($form, 'pl') || self::startsWith($form, 'p')) {
            return 5;
        }

        return -1;
    }

    private static function startsWith(string $s, string $prefix): bool
    {
        $n = self::strlenRec($prefix, 0);

        return self::strlenRec($s, 0) >= $n && \substr($s, 0, $n) === $prefix;
    }

    private static function cmpInt(int $a, int $b): int
    {
        if ($a < $b) {
            return -1;
        }
        if ($a > $b) {
            return 1;
        }

        return 0;
    }
}
