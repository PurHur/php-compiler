<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * parse_str() for compiled JIT/AOT modules (#9295, php-in-PHP).
 *
 * SSOT: {@see ParseStrEngine} + {@see VmParseStr::mergeInto()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(parse_str)
 */
final class ParseStrJitHelper
{
    public static function parseInto(HashTable $dest, string $encoded): void
    {
        VmParseStr::mergeInto($dest, ParseStrEngine::parse($encoded));
    }
}
