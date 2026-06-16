<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * php_uname() VM entry — pure PHP utsname probe ({@see VmUnamePure}, #8904).
 *
 * php-src: ext/standard/info.c — PHP_FUNCTION(php_uname)
 */
final class VmUnameNative
{
    public static function available(): bool
    {
        return VmUnamePure::available();
    }

    public static function php_uname(string $mode = 'a'): string
    {
        return VmUnamePure::php_uname($mode);
    }
}
