<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ucwords() for compiled JIT/AOT modules (#14717, #21726, #27049, php-in-PHP).
 *
 * Logic mirrors VmString asciiUcwords / asciiUcwordsEx — self-contained (no VmString call) so
 * NestedJIT helper units are not ExternalMethod-stubbed (#16075 / peer StrrevJitHelper #27007 /
 * QuotemetaJitHelper #27011). Prior VmString delegate segfaulted after c:main_before_php under
 * thin AOT (#27049).
 *
 * Title-case via match on one-byte lowercase literals (no native ord()/chr()). Word-start flag
 * is a '0'/'1' char reassigned every iteration (Soundex twin-stream discipline #26882).
 *
 * Avoid `ClassName::` tokens in this file — HelperRuntimeCache same-dir dep scan (#23458).
 *
 * php-src: ext/standard/string.c — php_ucwords() / php_ucwords_ex()
 */
final class UcwordsJitHelper
{
    /** php-src PHP_STR_WHITESPACE / VmString TRIM_DEFAULT. */
    private const DEFAULT_SEPARATORS = " \t\n\r\0\x0B";

    public static function ucwordsArgv(string $string): string
    {
        return self::ucwordsExArgv($string, self::DEFAULT_SEPARATORS);
    }

    public static function ucwordsExArgv(string $string, string $separators): string
    {
        $len = 0;
        while (isset($string[$len])) {
            ++$len;
        }
        if (0 === $len) {
            return '';
        }
        $atWordStart = '1';
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            // NestedJIT: assign piece from both branches — do not clear then maybe-set (#26882).
            $piece = $ch;
            if ('1' === $atWordStart) {
                $piece = self::upperAsciiLower($ch);
            }
            $out .= $piece;
            $atWordStart = self::sepFlag($piece, $separators);
        }

        return $out;
    }

    /** NestedJIT-safe ASCII a–z → A–Z; non-lowercase pass through (#27049). */
    private static function upperAsciiLower(string $ch): string
    {
        return match ($ch) {
            'a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D', 'e' => 'E', 'f' => 'F', 'g' => 'G',
            'h' => 'H', 'i' => 'I', 'j' => 'J', 'k' => 'K', 'l' => 'L', 'm' => 'M', 'n' => 'N',
            'o' => 'O', 'p' => 'P', 'q' => 'Q', 'r' => 'R', 's' => 'S', 't' => 'T', 'u' => 'U',
            'v' => 'V', 'w' => 'W', 'x' => 'X', 'y' => 'Y', 'z' => 'Z',
            default => $ch,
        };
    }

    /** '1' when $ch is in $separators, else '0' (php_ucwords_ex delim scan). */
    private static function sepFlag(string $ch, string $separators): string
    {
        $j = 0;
        while (isset($separators[$j])) {
            if ($separators[$j] === $ch) {
                return '1';
            }
            ++$j;
        }

        return '0';
    }
}
