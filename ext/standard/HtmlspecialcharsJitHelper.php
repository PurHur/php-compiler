<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for htmlspecialchars() runtime (#9445, #20487, php-in-PHP).
 *
 * Logic mirrors {@see VmString::htmlspecialchars()} UTF-8 subset (php-src ext/standard/html.c).
 * Self-contained (no VmString / strlen / substr) so NestedJIT helper units are not
 * ExternalMethod-stubbed (#16075; peer Bin2hex #20452 / HashEquals #20469).
 * Length via isset-scan; UTF-8 structural checks via {@see ord()} (AOT cannot lower
 * string <= / < on byte chars — #22845).
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
            // AOT NestedJIT cannot lower string <= / < on byte chars (TYPE_SMALLER
            // pair 134/132); use ord() so htmlspecialchars() is non-empty (#22845).
            $b0 = \ord($ch);
            if ($b0 <= 0x7F) {
                ++$i;
                continue;
            }
            $need = self::utf8NeedByte($b0);
            if (0 === $need) {
                return false;
            }
            if (!self::utf8TrailValidBytes($string, $i, $need, $b0)) {
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
            $b0 = \ord($ch);
            if ($b0 <= 0x7F) {
                $out .= $ch;
                ++$i;
                continue;
            }
            $need = self::utf8NeedByte($b0);
            if (0 !== $need && self::utf8TrailValidBytes($string, $i, $need, $b0)) {
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
    private static function utf8NeedByte(int $lead): int
    {
        if ($lead >= 0xC2 && $lead <= 0xDF) {
            return 1;
        }
        if ($lead >= 0xE0 && $lead <= 0xEF) {
            return 2;
        }
        if ($lead >= 0xF0 && $lead <= 0xF4) {
            return 3;
        }

        return 0;
    }

    private static function utf8TrailValidBytes(string $string, int $i, int $need, int $lead): bool
    {
        for ($j = 1; $j <= $need; ++$j) {
            if (!isset($string[$i + $j])) {
                return false;
            }
            $next = \ord($string[$i + $j]);
            if ($next < 0x80 || $next > 0xBF) {
                return false;
            }
        }
        // Overlong / surrogate / out-of-range second-byte windows (php-src utf8 checks).
        $b1 = \ord($string[$i + 1]);
        if (0xE0 === $lead && $b1 < 0xA0) {
            return false;
        }
        if (0xED === $lead && $b1 > 0x9F) {
            return false;
        }
        if (0xF0 === $lead && $b1 < 0x90) {
            return false;
        }
        if (0xF4 === $lead && $b1 > 0x8F) {
            return false;
        }

        return true;
    }
}
