<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_fill() for compiled JIT/AOT modules (#13501, php-in-PHP).
 *
 * SSOT: {@see array_fill::execute()}
 * php-src: ext/standard/array.c — php_array_fill()
 */
final class ArrayFillJitHelper
{
    public static function fillCopy(int $startIndex, int $count, Variable $value): HashTable
    {
        $ht = new HashTable();
        for ($i = 0; $i < $count; ++$i) {
            $stored = new Variable();
            $stored->copyFrom($value);
            $ht->addIndex($startIndex + $i, $stored);
        }

        return $ht;
    }
}
