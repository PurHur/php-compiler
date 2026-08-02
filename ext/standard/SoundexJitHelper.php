<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * soundex() for compiled JIT/AOT modules (#13448, #26882, php-in-PHP).
 *
 * Self-contained (no VmString call) so NestedJIT helper units are not
 * ExternalMethod-stubbed (#16075 / peer StrRot13JitHelper #26868).
 * Twin raw/flag streams with tracked length; always `.=`; fixed-4 pad.
 * NestedJIT: never clear-then-conditionally-set a loop local — the clear is a
 * silent no-op and the prior iteration's value leaks (#26882).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(soundex)
 */
final class SoundexJitHelper
{
    public static function soundexArgv(string $string): string
    {
        $len = 0;
        while (isset($string[$len])) {
            ++$len;
        }
        $alphas = '';
        for ($i = 0; $i < $len; ++$i) {
            $alphas .= self::toUpperAlpha($string[$i]);
        }
        $alen = 0;
        while (isset($alphas[$alen])) {
            ++$alen;
        }
        if (0 === $alen) {
            return '0000';
        }
        $first = $alphas[0];
        $last = self::soundexDigitChar($first);
        $raw = '';
        $flags = '';
        $rlen = 0;
        for ($i = 1; $i < $alen; ++$i) {
            $digit = self::soundexDigitChar($alphas[$i]);
            $flag = '0';
            if ($digit !== $last) {
                $last = $digit;
                if ('0' !== $digit) {
                    $flag = '1';
                }
            }
            $raw .= $digit;
            $flags .= $flag;
            ++$rlen;
        }
        $digits = '';
        for ($i = 0; $i < $rlen; ++$i) {
            // NestedJIT: assign piece from both branches — do not clear then maybe-set (#26882).
            $piece = $raw[$i];
            if ('1' !== $flags[$i]) {
                $piece = '';
            }
            $digits .= $piece;
        }
        $padded = $first.$digits.'0000';
        $out = '';
        for ($i = 0; $i < 4; ++$i) {
            $out .= $padded[$i];
        }

        return $out;
    }

    private static function toUpperAlpha(string $ch): string
    {
        return match ($ch) {
            'A' => 'A', 'a' => 'A',
            'B' => 'B', 'b' => 'B',
            'C' => 'C', 'c' => 'C',
            'D' => 'D', 'd' => 'D',
            'E' => 'E', 'e' => 'E',
            'F' => 'F', 'f' => 'F',
            'G' => 'G', 'g' => 'G',
            'H' => 'H', 'h' => 'H',
            'I' => 'I', 'i' => 'I',
            'J' => 'J', 'j' => 'J',
            'K' => 'K', 'k' => 'K',
            'L' => 'L', 'l' => 'L',
            'M' => 'M', 'm' => 'M',
            'N' => 'N', 'n' => 'N',
            'O' => 'O', 'o' => 'O',
            'P' => 'P', 'p' => 'P',
            'Q' => 'Q', 'q' => 'Q',
            'R' => 'R', 'r' => 'R',
            'S' => 'S', 's' => 'S',
            'T' => 'T', 't' => 'T',
            'U' => 'U', 'u' => 'U',
            'V' => 'V', 'v' => 'V',
            'W' => 'W', 'w' => 'W',
            'X' => 'X', 'x' => 'X',
            'Y' => 'Y', 'y' => 'Y',
            'Z' => 'Z', 'z' => 'Z',
            default => '',
        };
    }

    private static function soundexDigitChar(string $upper): string
    {
        return match ($upper) {
            'B' => '1', 'F' => '1', 'P' => '1', 'V' => '1',
            'C' => '2', 'G' => '2', 'J' => '2', 'K' => '2',
            'Q' => '2', 'S' => '2', 'X' => '2', 'Z' => '2',
            'D' => '3', 'T' => '3',
            'L' => '4',
            'M' => '5', 'N' => '5',
            'R' => '6',
            default => '0',
        };
    }
}
