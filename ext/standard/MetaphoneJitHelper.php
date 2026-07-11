<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * metaphone() for compiled JIT/AOT modules (#13447, php-in-PHP).
 *
 * SSOT: {@see VmMetaphone::encode()}
 * php-src: ext/standard/metaphone.c — PHP_FUNCTION(metaphone)
 */
final class MetaphoneJitHelper
{
    public static function metaphoneArgv(string $string, int $maxPhonemes): string
    {
        return VmMetaphone::encode($string, $maxPhonemes);
    }
}
