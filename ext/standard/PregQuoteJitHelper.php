<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * preg_quote() for compiled JIT/AOT modules (#14743, php-in-PHP).
 *
 * SSOT: {@see VmString::pregQuote()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(preg_quote)
 */
final class PregQuoteJitHelper
{
    public static function pregQuoteArgv(string $string, ?string $delimiter = null): string
    {
        return VmString::pregQuote($string, $delimiter);
    }
}
