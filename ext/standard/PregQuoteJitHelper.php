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
    /**
     * NestedJIT types nullable string as __value__*; ABI bridge passes __string__*.
     * Use empty string for "no delimiter" so both sides stay __string__* (#21109).
     */
    public static function pregQuoteArgv(string $string, string $delimiter = ''): string
    {
        return VmString::pregQuote($string, '' === $delimiter ? null : $delimiter);
    }
}
