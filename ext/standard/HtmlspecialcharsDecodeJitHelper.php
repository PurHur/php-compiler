<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for htmlspecialchars_decode() runtime (#14820, php-in-PHP).
 *
 * Logic mirrors {@see VmString::htmlspecialchars_decode()} entity subset (php-src ext/standard/html.c).
 * Self-contained so parseAndCompile() does not depend on compiling all of VmString.php.
 */
final class HtmlspecialcharsDecodeJitHelper
{
    public static function htmlspecialcharsDecodeArgv(string $string, int $flags): string
    {
        $quoteBoth = ENT_QUOTES === ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $out = '';
        $len = \strlen($string);
        $i = 0;
        while ($i < $len) {
            if ('&' !== $string[$i]) {
                $out .= $string[$i];
                ++$i;
                continue;
            }
            if (self::entityAt($string, $i, $len, '&amp;', 5)) {
                $out .= '&';
                $i += 5;
            } elseif (self::entityAt($string, $i, $len, '&lt;', 4)) {
                $out .= '<';
                $i += 4;
            } elseif (self::entityAt($string, $i, $len, '&gt;', 4)) {
                $out .= '>';
                $i += 4;
            } elseif (($quoteBoth || $quoteDouble) && self::entityAt($string, $i, $len, '&quot;', 6)) {
                $out .= '"';
                $i += 6;
            } elseif ($quoteBoth && self::entityAt($string, $i, $len, '&#039;', 6)) {
                $out .= "'";
                $i += 6;
            } elseif ($quoteBoth && self::entityAt($string, $i, $len, '&#39;', 5)) {
                $out .= "'";
                $i += 5;
            } elseif (0 !== ($flags & ENT_HTML5) && ENT_QUOTES === ($flags & ENT_QUOTES)
                && self::entityAt($string, $i, $len, '&apos;', 6)) {
                $out .= "'";
                $i += 6;
            } else {
                $out .= '&';
                ++$i;
            }
        }

        return $out;
    }

    private static function entityAt(string $string, int $pos, int $len, string $entity, int $entityLen): bool
    {
        if ($pos + $entityLen > $len) {
            return false;
        }
        for ($j = 0; $j < $entityLen; ++$j) {
            if ($string[$pos + $j] !== $entity[$j]) {
                return false;
            }
        }

        return true;
    }
}
