<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_filter() default (no callback) for compiled JIT/AOT modules (#12370, php-in-PHP).
 *
 * SSOT: {@see array_filter::filterDefault()}
 * php-src: ext/standard/array.c — php_array_filter()
 */
final class ArrayFilterJitHelper
{
    public static function filterDefault(HashTable $ht): HashTable
    {
        $out = new HashTable();
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            if (boolval::isTruthy($value)) {
                array_map::appendKeyedCopy($out, $key, $value);
            }
        }

        return $out;
    }
}
