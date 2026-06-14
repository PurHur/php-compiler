<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules that call convert_cyr_string() (#4649).
 *
 * php-src: ext/standard/cyr_convert.c — php_convert_cyr_string().
 */
final class ConvertCyrStringJitHelper
{
    public static function convert(string $str, string $from, string $to): string
    {
        return VmConvertCyrString::convert($str, $from, $to);
    }
}
