<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Locale collation via host strcoll/strxfrm when available (#4376, #13566).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(strcoll), PHP_FUNCTION(strxfrm)
 */
final class VmLocaleCollate
{
    public static function strcoll(string $a, string $b): int
    {
        if (\function_exists('strcoll')) {
            return \strcoll($a, $b);
        }

        return VmString::strcmp($a, $b);
    }

    public static function strxfrm(string $string): string
    {
        if (\function_exists('strxfrm')) {
            return \strxfrm($string);
        }

        return $string;
    }
}
