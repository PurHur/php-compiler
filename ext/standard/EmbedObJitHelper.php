<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * MCJIT embed echo formatting SSOT (#9956, php-in-PHP).
 *
 * JIT embed bridges use snprintf with the same formats; see {@see \PHPCompiler\JIT\Builtin\EmbedObEchoBridge}.
 * php-src: Zend/zend_print_variable — int/double stringification for echo
 */
final class EmbedObJitHelper
{
    public static function formatInt64(int $value): string
    {
        return (string) $value;
    }

    public static function formatDouble(float $value): string
    {
        return \sprintf('%.14g', $value);
    }
}
