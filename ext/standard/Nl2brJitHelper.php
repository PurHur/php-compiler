<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * nl2br() for compiled JIT/AOT modules (#14714, php-in-PHP).
 *
 * SSOT: {@see VmString::nl2br()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(nl2br)
 */
final class Nl2brJitHelper
{
    public static function nl2brArgv(string $string, int $useXhtml): string
    {
        return VmString::nl2br($string, 0 !== $useXhtml);
    }
}
