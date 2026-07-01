<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * quotemeta() for compiled JIT/AOT modules (#14705, php-in-PHP).
 *
 * SSOT: {@see VmString::quotemeta()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(quotemeta)
 */
final class QuotemetaJitHelper
{
    public static function quotemetaArgv(string $str): string
    {
        return VmString::quotemeta($str);
    }
}
