<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for htmlspecialchars() runtime (#9445, #20487, php-in-PHP).
 *
 * Logic mirrors {@see VmString::htmlspecialchars()} UTF-8 subset (php-src ext/standard/html.c).
 * Self-contained (no VmString / strlen / substr / str_replace) so NestedJIT helper units are not
 * ExternalMethod-stubbed (#16075; peer Bin2hex #20452 / HashEquals #20469).
 *
 * UTF-8 structural checks via {@see ord()} (AOT NestedJIT cannot lower string <= / < on byte
 * chars — TYPE_SMALLER pair 134/132; #22845).
 *
 * Escape accumulation uses recursion rather than `$out .=` in a loop: NestedJIT helper TUs
 * drop loop-carried string concatenations (top-level AOT loops are fine; helper embed is not).
 * MiniWebApp / title strings are short; long-string loop concat belongs in a NestedJIT fix.
 */
final class HtmlspecialcharsJitHelper
{
    public static function htmlspecialchars(string $string, int $flags): string
    {
        if (!self::isValidUtf8($string)) {
            if (0 === ($flags & ENT_SUBSTITUTE)) {
                return '';
            }
            $string = self::substituteInvalidUtf8($string, 0);
        }

        return self::escapeAt($string, 0, $flags);
    }

    private static function escapeAt(string $string, int $i, int $flags): string
    {
        if (!isset($string[$i])) {
            return '';
        }
        $ch = $string[$i];
        $quoteBoth = ENT_QUOTES === ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $entHtml5 = 0 !== ($flags & ENT_HTML5);
        if ('&' === $ch) {
            $piece = '&amp;';
        } elseif ('<' === $ch) {
            $piece = '&lt;';
        } elseif ('>' === $ch) {
            $piece = '&gt;';
        } elseif ('"' === $ch) {
            $piece = ($quoteBoth || $quoteDouble) ? '&quot;' : '"';
        } elseif ("'" === $ch) {
            if ($quoteBoth) {
                $piece = $entHtml5 ? '&apos;' : '&#039;';
            } else {
                $piece = "'";
            }
        } else {
            $piece = $ch;
        }

        return $piece.self::escapeAt($string, $i + 1, $flags);
    }

    private static function isValidUtf8(string $string): bool
    {
        $i = 0;
        // Length-style isset scan (no string accumulator) is NestedJIT-safe (#22845).
        while (isset($string[$i])) {
            $ch = $string[$i];
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

    private static function substituteInvalidUtf8(string $string, int $i): string
    {
        if (!isset($string[$i])) {
            return '';
        }
        $ch = $string[$i];
        $b0 = \ord($ch);
        if ($b0 <= 0x7F) {
            return $ch.self::substituteInvalidUtf8($string, $i + 1);
        }
        $need = self::utf8NeedByte($b0);
        if (0 !== $need && self::utf8TrailValidBytes($string, $i, $need, $b0)) {
            // Unrolled short UTF-8 sequence copy — NestedJIT drops loop-carried `$out .=`.
            $chunk = $string[$i];
            if ($need >= 1) {
                $chunk = $chunk.$string[$i + 1];
            }
            if ($need >= 2) {
                $chunk = $chunk.$string[$i + 2];
            }
            if ($need >= 3) {
                $chunk = $chunk.$string[$i + 3];
            }

            return $chunk.self::substituteInvalidUtf8($string, $i + $need + 1);
        }

        return "\xEF\xBF\xBD".self::substituteInvalidUtf8($string, $i + 1);
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
        if ($need >= 1) {
            if (!isset($string[$i + 1])) {
                return false;
            }
            $n1 = \ord($string[$i + 1]);
            if ($n1 < 0x80 || $n1 > 0xBF) {
                return false;
            }
        }
        if ($need >= 2) {
            if (!isset($string[$i + 2])) {
                return false;
            }
            $n2 = \ord($string[$i + 2]);
            if ($n2 < 0x80 || $n2 > 0xBF) {
                return false;
            }
        }
        if ($need >= 3) {
            if (!isset($string[$i + 3])) {
                return false;
            }
            $n3 = \ord($string[$i + 3]);
            if ($n3 < 0x80 || $n3 > 0xBF) {
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
