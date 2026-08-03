<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for htmlspecialchars() runtime (#9445, #20487, #27290, php-in-PHP).
 *
 * Escape subset mirrors {@see VmString::htmlspecialchars()} / php-src ext/standard/html.c
 * for & < > " ' plus double_encode=false entity preservation. Self-contained for NestedJIT (#16075).
 *
 * NestedJIT / AOT cannot lower a loop-carried string accumulator when branches
 * compare string offsets (method-return / dynamic args returned empty — #25345).
 * Recurse with `$ch.$rest` / entity.'.'.$rest instead of mutating an accumulator.
 */
final class HtmlspecialcharsJitHelper
{
    public static function htmlspecialchars(string $string, int $flags): string
    {
        return self::htmlspecialcharsEx($string, $flags, 1);
    }

    /** UTF-8 htmlspecialchars with double_encode (0/1) for thin AOT (#27290). */
    public static function htmlspecialcharsEx(string $string, int $flags, int $doubleEncode): string
    {
        return self::escapeFrom($string, $flags, 0, 0 !== $doubleEncode);
    }

    /** Public so NestedJIT helper TUs bind the recursive callee (#25345). */
    public static function escapeFrom(string $string, int $flags, int $i, bool $doubleEncode): string
    {
        if (!isset($string[$i])) {
            return '';
        }
        $ch = $string[$i];
        if ('&' === $ch && !$doubleEncode) {
            $entityLen = self::existingEntityLen($string, $i);
            if ($entityLen > 0) {
                return self::copyBytes($string, $i, $entityLen)
                    .self::escapeFrom($string, $flags, $i + $entityLen, $doubleEncode);
            }
        }
        $rest = self::escapeFrom($string, $flags, $i + 1, $doubleEncode);
        $quoteBoth = ENT_QUOTES === ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $entHtml5 = 0 !== ($flags & ENT_HTML5);
        if ('&' === $ch) {
            return '&amp;'.$rest;
        }
        if ('<' === $ch) {
            return '&lt;'.$rest;
        }
        if ('>' === $ch) {
            return '&gt;'.$rest;
        }
        if ('"' === $ch) {
            return (($quoteBoth || $quoteDouble) ? '&quot;' : '"').$rest;
        }
        if ("'" === $ch) {
            return ($quoteBoth ? ($entHtml5 ? '&apos;' : '&#039;') : "'").$rest;
        }

        return $ch.$rest;
    }

    /** Named / numeric entity length at $i when double_encode=false (php-src html.c). */
    public static function existingEntityLen(string $string, int $i): int
    {
        if (!isset($string[$i]) || '&' !== $string[$i]) {
            return 0;
        }
        if (self::entityMatch($string, $i, '&amp;', 0)) {
            return 5;
        }
        if (self::entityMatch($string, $i, '&lt;', 0)) {
            return 4;
        }
        if (self::entityMatch($string, $i, '&gt;', 0)) {
            return 4;
        }
        if (self::entityMatch($string, $i, '&quot;', 0)) {
            return 6;
        }
        if (self::entityMatch($string, $i, '&#039;', 0)) {
            return 6;
        }
        if (self::entityMatch($string, $i, '&#39;', 0)) {
            return 5;
        }

        return self::numericEntityLen($string, $i);
    }

    public static function entityMatch(string $string, int $i, string $entity, int $j): bool
    {
        if (!isset($entity[$j])) {
            return true;
        }
        if (!isset($string[$i + $j]) || $string[$i + $j] !== $entity[$j]) {
            return false;
        }

        return self::entityMatch($string, $i, $entity, $j + 1);
    }

    public static function copyBytes(string $string, int $i, int $len): string
    {
        if ($len <= 0) {
            return '';
        }

        return $string[$i].self::copyBytes($string, $i + 1, $len - 1);
    }

    public static function numericEntityLen(string $string, int $i): int
    {
        if (!isset($string[$i]) || '&' !== $string[$i]) {
            return 0;
        }
        if (!isset($string[$i + 1]) || '#' !== $string[$i + 1]) {
            return 0;
        }
        if (!isset($string[$i + 2])) {
            return 0;
        }
        $j = $i + 2;
        if ('x' === $string[$j] || 'X' === $string[$j]) {
            $j = $j + 1;
            if (!isset($string[$j]) || !self::isHexDigit($string[$j])) {
                return 0;
            }

            return self::scanHexEntityEnd($string, $i, $j + 1);
        }
        if (!self::isDigit($string[$j])) {
            return 0;
        }

        return self::scanDecEntityEnd($string, $i, $j + 1);
    }

    public static function scanHexEntityEnd(string $string, int $start, int $j): int
    {
        if (!isset($string[$j])) {
            return 0;
        }
        if (self::isHexDigit($string[$j])) {
            return self::scanHexEntityEnd($string, $start, $j + 1);
        }
        if (';' === $string[$j]) {
            return ($j - $start) + 1;
        }

        return 0;
    }

    public static function scanDecEntityEnd(string $string, int $start, int $j): int
    {
        if (!isset($string[$j])) {
            return 0;
        }
        if (self::isDigit($string[$j])) {
            return self::scanDecEntityEnd($string, $start, $j + 1);
        }
        if (';' === $string[$j]) {
            return ($j - $start) + 1;
        }

        return 0;
    }

    public static function isDigit(string $ch): bool
    {
        return $ch >= '0' && $ch <= '9';
    }

    public static function isHexDigit(string $ch): bool
    {
        return self::isDigit($ch)
            || ($ch >= 'a' && $ch <= 'f')
            || ($ch >= 'A' && $ch <= 'F');
    }
}
