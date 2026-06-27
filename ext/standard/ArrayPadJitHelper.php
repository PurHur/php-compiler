<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_pad() for compiled JIT/AOT modules (#12476, php-in-PHP).
 *
 * SSOT: {@see HashTable::padCopy()}
 * php-src: ext/standard/array.c — php_array_pad()
 */
final class ArrayPadJitHelper
{
    public static function padCopy(HashTable $ht, int $length, Variable $value): HashTable
    {
        return $ht->padCopy($length, $value);
    }
}
