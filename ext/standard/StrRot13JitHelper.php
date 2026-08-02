<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_rot13() for compiled JIT/AOT modules (#14896, #26868, php-in-PHP).
 *
 * Logic mirrors {@see VmString}::strRot13 — self-contained (no VmString call) so NestedJIT
 * helper units are not ExternalMethod-stubbed (#16075 / peer Bin2hexJitHelper #20452).
 * Letter transform via match on one-byte literals (no native ord()/chr()).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_rot13)
 */
final class StrRot13JitHelper
{
    public static function rot13Argv(string $input): string
    {
        $len = 0;
        while (isset($input[$len])) {
            ++$len;
        }
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $out .= self::rot13Char($input[$i]);
        }

        return $out;
    }

    /** NestedJIT-safe ASCII letter ROT13; non-letters pass through (#26868). */
    private static function rot13Char(string $ch): string
    {
        return match ($ch) {
            'A' => 'N', 'B' => 'O', 'C' => 'P', 'D' => 'Q', 'E' => 'R', 'F' => 'S', 'G' => 'T',
            'H' => 'U', 'I' => 'V', 'J' => 'W', 'K' => 'X', 'L' => 'Y', 'M' => 'Z',
            'N' => 'A', 'O' => 'B', 'P' => 'C', 'Q' => 'D', 'R' => 'E', 'S' => 'F', 'T' => 'G',
            'U' => 'H', 'V' => 'I', 'W' => 'J', 'X' => 'K', 'Y' => 'L', 'Z' => 'M',
            'a' => 'n', 'b' => 'o', 'c' => 'p', 'd' => 'q', 'e' => 'r', 'f' => 's', 'g' => 't',
            'h' => 'u', 'i' => 'v', 'j' => 'w', 'k' => 'x', 'l' => 'y', 'm' => 'z',
            'n' => 'a', 'o' => 'b', 'p' => 'c', 'q' => 'd', 'r' => 'e', 's' => 'f', 't' => 'g',
            'u' => 'h', 'v' => 'i', 'w' => 'j', 'x' => 'k', 'y' => 'l', 'z' => 'm',
            default => $ch,
        };
    }
}
