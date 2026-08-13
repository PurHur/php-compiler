<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * soundex() for compiled JIT/AOT modules (#13448, #26882, #30790, php-in-PHP).
 *
 * Thin argv bridge — algorithm in {@see VmSoundex}, NestedJIT-bundled with this file
 * (peer {@see MetaphoneJitHelper} / #26794).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(soundex)
 */
final class SoundexJitHelper
{
    public static function soundexArgv(string $string): string
    {
        return VmSoundex::encode($string);
    }
}
