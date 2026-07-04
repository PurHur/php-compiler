<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_rand() for compiled JIT/AOT modules (#16135, php-in-PHP).
 *
 * SSOT: {@see VmArray::arrayRandPacked()}
 * php-src: ext/standard/array.c — php_array_rand / php_array_pick_keys
 */
final class ArrayRandJitHelper
{
    public static function pick(HashTable $ht, int $num): Variable
    {
        return VmArray::arrayRandPacked($ht, $num);
    }
}
