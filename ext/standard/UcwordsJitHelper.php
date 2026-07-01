<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ucwords() for compiled JIT/AOT modules (#14717, php-in-PHP).
 *
 * SSOT: {@see VmString::asciiUcwords()} / {@see VmString::asciiUcwordsEx()}
 * php-src: ext/standard/string.c — php_ucwords() / php_ucwords_ex()
 */
final class UcwordsJitHelper
{
    public static function ucwordsArgv(string $string): string
    {
        return VmString::asciiUcwords($string);
    }

    public static function ucwordsExArgv(string $string, string $separators): string
    {
        return VmString::asciiUcwordsEx($string, $separators);
    }
}
