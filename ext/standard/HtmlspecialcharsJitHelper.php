<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for htmlspecialchars() runtime (#9445, #20487, php-in-PHP).
 *
 * Logic mirrors {@see VmString::htmlspecialchars()} UTF-8 subset (php-src ext/standard/html.c).
 * Self-contained (no VmString / strlen / ord / substr) so NestedJIT helper units are not
 * ExternalMethod-stubbed (#16075; peer Bin2hex #20452 / HashEquals #20469).
 * Length via isset-scan; UTF-8 structural checks via one-byte string range compares.
 */
final class HtmlspecialcharsJitHelper
{
    public static function htmlspecialchars(string $string, int $flags): string
    {
        if (!self::isValidUtf8($string)) {
            if (0 === ($flags & ENT_SUBSTITUTE)) {
                return '';
            }
            $string = self::substituteInvalidUtf8($string);
        }
        $quoteBoth = ENT_QUOTES === ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $entHtml5 = 0 !== ($flags & ENT_HTML5);
        $out = '';
        $len = 0;
        while (isset($string[$len])) {
            ++$len;
        }
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            switch ($ch) {
                case '&':
                    $out .= '&amp;';
                    break;
                case '<':
                    $out .= '&lt;';
                    break;
                case '>':
                    $out .= '&gt;';
                    break;
                case '"':
                    $out .= ($quoteBoth || $quoteDouble) ? '&quot;' : '"';
                    break;
                case "'":
                    $out .= $quoteBoth ? ($entHtml5 ? '&apos;' : '&#039;') : "'";
                    break;
                default:
                    $out .= $ch;
            }
        }

        return $out;
    }

    private static function isValidUtf8(string $string): bool
    {
        $i = 0;
        while (isset($string[$i])) {
            $ch = $string[$i];
            if ($ch <= "\x7F") {
                ++$i;
                continue;
            }
            $need = self::utf8Need($ch);
            if (0 === $need) {
                return false;
            }
            if (!self::utf8TrailValid($string, $i, $need, $ch)) {
                return false;
            }
            $i += $need + 1;
        }

        return true;
    }

    private static function substituteInvalidUtf8(string $string): string
    {
        $out = '';
        $i = 0;
        while (isset($string[$i])) {
            $ch = $string[$i];
            if ($ch <= "\x7F") {
                $out .= $ch;
                ++$i;
                continue;
            }
            $need = self::utf8Need($ch);
            if (0 !== $need && self::utf8TrailValid($string, $i, $need, $ch)) {
                for ($j = 0; $j <= $need; ++$j) {
                    $out .= $string[$i + $j];
                }
                $i += $need + 1;
            } else {
                $out .= "\xEF\xBF\xBD";
                ++$i;
            }
        }

        return $out;
    }

    /** @return int continuation bytes required, or 0 if lead is invalid */
    private static function utf8Need(string $lead): int
    {
        if ($lead >= "\xC2" && $lead <= "\xDF") {
            return 1;
        }
        if ($lead >= "\xE0" && $lead <= "\xEF") {
            return 2;
        }
        if ($lead >= "\xF0" && $lead <= "\xF4") {
            return 3;
        }

        return 0;
    }

    private static function utf8TrailValid(string $string, int $i, int $need, string $lead): bool
    {
        for ($j = 1; $j <= $need; ++$j) {
            if (!isset($string[$i + $j])) {
                return false;
            }
            $next = $string[$i + $j];
            if ($next < "\x80" || $next > "\xBF") {
                return false;
            }
        }
        // Overlong / surrogate / out-of-range second-byte windows (php-src utf8 checks).
        $b1 = $string[$i + 1];
        if ("\xE0" === $lead && $b1 < "\xA0") {
            return false;
        }
        if ("\xED" === $lead && $b1 > "\x9F") {
            return false;
        }
        if ("\xF0" === $lead && $b1 < "\x90") {
            return false;
        }
        if ("\xF4" === $lead && $b1 > "\x8F") {
            return false;
        }

        return true;
    }
}
