<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * Lowered into JIT/AOT modules that call mb_strwidth() / mb_strimwidth() at runtime (#3495, php-in-PHP).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strwidth), PHP_FUNCTION(mb_strimwidth).
 */
final class MbStrwidthJitHelper
{
    public static function strwidth(string $string, string $encoding): int
    {
        return VmMbstring::strwidth($string, $encoding);
    }

    public static function strimwidth(
        string $string,
        int $from,
        int $width,
        string $trimmarker,
        string $encoding
    ): string {
        return VmMbstring::strimwidth($string, $from, $width, $trimmarker, $encoding);
    }

    public static function strPad(
        string $input,
        int $padLength,
        string $padString,
        int $padType,
        string $encoding
    ): string {
        return VmMbstring::strPad($input, $padLength, $padString, $padType, $encoding);
    }
}
