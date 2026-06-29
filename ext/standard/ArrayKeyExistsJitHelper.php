<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_key_exists() / key_exists() for compiled JIT/AOT modules (#13735, php-in-PHP).
 *
 * SSOT: {@see HashTable::hasKey()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_key_exists)
 */
final class ArrayKeyExistsJitHelper
{
    public static function keyExists(Variable $key, HashTable $table): bool
    {
        return VmArray::keyExists($key, $table);
    }
}
