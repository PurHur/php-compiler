<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for htmlspecialchars_decode() runtime (#14820, php-in-PHP).
 *
 * Logic mirrors {@see VmString::htmlspecialchars_decode()} entity subset (php-src ext/standard/html.c).
 * Self-contained so parseAndCompile() does not depend on compiling all of VmString.php.
 *
 * NestedJIT / AOT cannot lower a loop-carried string accumulator when branches
 * compare string offsets (same defect as htmlspecialchars encode — #25345 / #27050).
 * Recurse with `$ch.$rest` / decoded-char.'.'.$rest instead of mutating an accumulator
 * or using `\strlen` + `while`.
 */
final class HtmlspecialcharsDecodeJitHelper
{
    public static function htmlspecialcharsDecodeArgv(string $string, int $flags): string
    {
        return self::decodeFrom($string, $flags, 0);
    }

    /** Public so NestedJIT helper TUs bind the recursive callee (#27050 / peer #25345). */
    public static function decodeFrom(string $string, int $flags, int $i): string
    {
        if (!isset($string[$i])) {
            return '';
        }
        if ('&' !== $string[$i]) {
            return $string[$i].self::decodeFrom($string, $flags, $i + 1);
        }

        $quoteBoth = ENT_QUOTES === ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));

        if (self::entityMatch($string, $i, '&amp;', 0)) {
            return '&'.self::decodeFrom($string, $flags, $i + 5);
        }
        if (self::entityMatch($string, $i, '&lt;', 0)) {
            return '<'.self::decodeFrom($string, $flags, $i + 4);
        }
        if (self::entityMatch($string, $i, '&gt;', 0)) {
            return '>'.self::decodeFrom($string, $flags, $i + 4);
        }
        if (($quoteBoth || $quoteDouble) && self::entityMatch($string, $i, '&quot;', 0)) {
            return '"'.self::decodeFrom($string, $flags, $i + 6);
        }
        if ($quoteBoth && self::entityMatch($string, $i, '&#039;', 0)) {
            return "'".self::decodeFrom($string, $flags, $i + 6);
        }
        if ($quoteBoth && self::entityMatch($string, $i, '&#39;', 0)) {
            return "'".self::decodeFrom($string, $flags, $i + 5);
        }
        if (0 !== ($flags & ENT_HTML5) && ENT_QUOTES === ($flags & ENT_QUOTES)
            && self::entityMatch($string, $i, '&apos;', 0)) {
            return "'".self::decodeFrom($string, $flags, $i + 6);
        }

        return '&'.self::decodeFrom($string, $flags, $i + 1);
    }

    /** Recursive entity match — NestedJIT-safe (peer HtmlspecialcharsJitHelper::entityMatch). */
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
}
