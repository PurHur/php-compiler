<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * nl2br() for compiled JIT/AOT modules (#14714, #21630, #30813, php-in-PHP).
 *
 * Thin argv bridge — algorithm in {@see VmNl2br}, NestedJIT-bundled with this file
 * (peer {@see WordwrapJitHelper} / #30812). Solo NestedJIT of the former `$s[$i]`
 * helper path SIGSEGV'd under thin AOT after c:main_before_php.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(nl2br)
 */
final class Nl2brJitHelper
{
    public static function nl2brArgv(string $string, int $useXhtml): string
    {
        return VmNl2br::nl2br($string, $useXhtml);
    }
}
