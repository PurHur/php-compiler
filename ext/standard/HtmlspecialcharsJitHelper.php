<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for htmlspecialchars() runtime (#9445, php-in-PHP).
 *
 * Logic mirrors {@see VmString::htmlspecialchars()} UTF-8 subset (php-src ext/standard/html.c).
 * Self-contained so parseAndCompile() does not depend on compiling all of VmString.php.
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
        $quoteBoth = 0 !== ($flags & ENT_QUOTES);
        $quoteDouble = !$quoteBoth && (0 !== ($flags & ENT_COMPAT));
        $entHtml5 = 0 !== ($flags & ENT_HTML5);
        $out = '';
        $len = \strlen($string);
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
        $len = \strlen($string);
        for ($i = 0; $i < $len; ) {
            $byte = \ord($string[$i]);
            if ($byte < 0x80) {
                ++$i;
                continue;
            }
            $need = 0;
            if (($byte & 0xE0) === 0xC0) {
                $need = 1;
                $min = 0x80;
            } elseif (($byte & 0xF0) === 0xE0) {
                $need = 2;
                $min = 0x800;
            } elseif (($byte & 0xF8) === 0xF0) {
                $need = 3;
                $min = 0x10000;
            } else {
                return false;
            }
            if ($i + $need >= $len) {
                return false;
            }
            $cp = $byte & (0xFF >> (2 + $need));
            for ($j = 1; $j <= $need; ++$j) {
                $next = \ord($string[$i + $j]);
                if (($next & 0xC0) !== 0x80) {
                    return false;
                }
                $cp = ($cp << 6) | ($next & 0x3F);
            }
            if ($cp < $min || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
                return false;
            }
            $i += $need + 1;
        }

        return true;
    }

    private static function substituteInvalidUtf8(string $string): string
    {
        $out = '';
        $len = \strlen($string);
        for ($i = 0; $i < $len; ) {
            $byte = \ord($string[$i]);
            if ($byte < 0x80) {
                $out .= $string[$i];
                ++$i;
                continue;
            }
            $need = 0;
            $valid = true;
            if (($byte & 0xE0) === 0xC0) {
                $need = 1;
                $min = 0x80;
            } elseif (($byte & 0xF0) === 0xE0) {
                $need = 2;
                $min = 0x800;
            } elseif (($byte & 0xF8) === 0xF0) {
                $need = 3;
                $min = 0x10000;
            } else {
                $valid = false;
            }
            if ($valid && $i + $need < $len) {
                $cp = $byte & (0xFF >> (2 + $need));
                for ($j = 1; $j <= $need; ++$j) {
                    $next = \ord($string[$i + $j]);
                    if (($next & 0xC0) !== 0x80) {
                        $valid = false;
                        break;
                    }
                    $cp = ($cp << 6) | ($next & 0x3F);
                }
                if ($valid && ($cp < $min || ($cp >= 0xD800 && $cp <= 0xDFFF))) {
                    $valid = false;
                }
            } else {
                $valid = false;
            }
            if ($valid) {
                $out .= \substr($string, $i, $need + 1);
                $i += $need + 1;
            } else {
                $out .= "\xEF\xBF\xBD";
                ++$i;
            }
        }

        return $out;
    }
}
