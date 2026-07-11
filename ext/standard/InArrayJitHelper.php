<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * in_array() for compiled JIT/AOT modules (#12503, php-in-PHP).
 *
 * SSOT: {@see VmArray::contains()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(in_array)
 */
final class InArrayJitHelper
{
    public static function contains(Variable $needle, HashTable $haystack, bool $strict): bool
    {
        return VmArray::contains($needle, $haystack, $strict);
    }
}
