<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmHash;
use PHPCompiler\VM\HashTable;

/**
 * hash_algos() / hash_hmac_algos() for compiled JIT/AOT modules (#14909, #18908, php-in-PHP).
 *
 * SSOT: {@see VmHash::algos()} / {@see VmHash::hmacAlgos()}
 * php-src: ext/hash/hash.c — PHP_FUNCTION(hash_algos) / PHP_FUNCTION(hash_hmac_algos)
 */
final class HashAlgosJitHelper
{
    public static function algosArgv(): HashTable
    {
        return VmHash::algos();
    }

    public static function hmacAlgosArgv(): HashTable
    {
        return VmHash::hmacAlgos();
    }
}
