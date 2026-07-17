<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * grapheme_str_split() for compiled JIT/AOT modules (#19964, php-in-PHP).
 *
 * SSOT: {@see VmGrapheme::strSplit()}
 * php-src: ext/intl/grapheme/grapheme_string.c — PHP_FUNCTION(grapheme_strsplit)
 */
final class GraphemeStrSplitJitHelper
{
    public static function strSplitArgv(string $string, int $length): ?HashTable
    {
        $parts = VmGrapheme::strSplit($string, $length);
        if (false === $parts) {
            return null;
        }
        $out = new HashTable();
        foreach ($parts as $part) {
            $stored = new Variable();
            $stored->string($part);
            $out->append($stored);
        }

        return $out;
    }
}
