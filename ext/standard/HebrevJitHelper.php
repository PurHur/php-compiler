<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules that call hebrev() at runtime (#3450, php-in-PHP JIT phase).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(hebrev).
 */
final class HebrevJitHelper
{
    public static function convert(string $str, int $maxCharsPerLine = 0): string
    {
        return VmHebrev::convert($str, $maxCharsPerLine);
    }

    public static function convertWithNewlines(string $str, int $maxCharsPerLine = 0): string
    {
        return VmHebrev::convertWithNewlines($str, $maxCharsPerLine);
    }
}
