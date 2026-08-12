<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strxfrm() for compiled JIT/AOT modules (#30420, php-in-PHP).
 *
 * Leaf is `\strxfrm` → NestedJIT whitelist {@see strxfrm} →
 * {@see \PHPCompiler\JIT\Builtin\StringStrxfrm} → {@see JitStrxfrm} libc leaf
 * (nl_langinfo #30404 / fnmatch #30383 shape).
 * php-src: ext/standard/string.c — PHP_FUNCTION(strxfrm)
 */
final class StrxfrmJitHelper
{
    public static function strxfrmArgv(string $string): string
    {
        return \strxfrm($string);
    }
}
