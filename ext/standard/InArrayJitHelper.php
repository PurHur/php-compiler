<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * in_array() host/VM helper (#12503, php-in-PHP).
 *
 * JIT/AOT user-script emission uses {@see \PHPCompiler\JIT\InArrayLlvm} (NestedJIT of this
 * class → {@see VmArray::contains} was an external stub under thin AOT — #27120 / #579).
 * SSOT for VM execute() and unit tests: {@see VmArray::contains()}.
 * php-src: ext/standard/array.c — PHP_FUNCTION(in_array)
 */
final class InArrayJitHelper
{
    public static function contains(Variable $needle, HashTable $haystack, bool $strict): bool
    {
        return VmArray::contains($needle, $haystack, $strict);
    }
}
