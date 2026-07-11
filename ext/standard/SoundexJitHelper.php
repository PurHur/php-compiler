<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * soundex() for compiled JIT/AOT modules (#13448, php-in-PHP).
 *
 * SSOT: {@see VmString::soundex()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(soundex)
 */
final class SoundexJitHelper
{
    public static function soundexArgv(string $string): string
    {
        return VmString::soundex($string);
    }
}
