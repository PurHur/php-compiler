<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmHash;
use PHPCompiler\VM\HashTable;

/**
 * hash_algos() for compiled JIT/AOT modules (#14909, php-in-PHP).
 *
 * SSOT: {@see VmHash::algos()}
 * php-src: ext/hash/hash.c — PHP_FUNCTION(hash_algos)
 */
final class HashAlgosJitHelper
{
    public static function algosArgv(): HashTable
    {
        return VmHash::algos();
    }
}
