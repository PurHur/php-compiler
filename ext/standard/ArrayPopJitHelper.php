<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_pop() for compiled JIT/AOT modules (#12647, php-in-PHP).
 *
 * SSOT shared with {@see array_pop} VM execute()
 * php-src: ext/standard/array.c — php_array_pop()
 */
final class ArrayPopJitHelper
{
    public static function pop(HashTable $ht): Variable
    {
        $popped = $ht->popLast();
        $out = new Variable();
        if (null === $popped) {
            $out->null();

            return $out;
        }
        $out->copyFrom($popped);

        return $out;
    }
}
