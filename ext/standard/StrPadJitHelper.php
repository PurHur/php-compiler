<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_pad() for compiled JIT/AOT modules (#14863, php-in-PHP).
 *
 * SSOT: {@see VmString::strPad()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_pad)
 */
final class StrPadJitHelper
{
    public static function padArgv(string $input, int $padLength, string $padString, int $padType): string
    {
        return VmString::strPad($input, $padLength, $padString, $padType);
    }
}
