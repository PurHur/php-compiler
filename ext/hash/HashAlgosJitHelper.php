<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

/**
 * hash_algos() / hash_hmac_algos() for compiled JIT/AOT modules (#14909, #18908, #20652, php-in-PHP).
 *
 * Always NestedJIT'd via {@see \PHPCompiler\JIT\JitVmHelperLink} (no thin registry fork).
 * Kernel path: {@see phpc_hash_algos_kernel} / {@see phpc_hash_hmac_algos_kernel}
 * (Fpow #20664 shape — NestedJIT leaf avoids HashTable::append under user-script AOT).
 *
 * Return type is `array` (not {@see HashTable}): NestedJIT maps class HashTable to object
 * ABI and TypeErrors on the pointer (#20652). `array` → `__hashtable__*`.
 *
 * SSOT for VM: {@see \PHPCompiler\ext\standard\VmHash::algos()} / {@see \PHPCompiler\ext\standard\VmHash::hmacAlgos()}
 * php-src: ext/hash/hash.c — PHP_FUNCTION(hash_algos) / PHP_FUNCTION(hash_hmac_algos)
 */
final class HashAlgosJitHelper
{
    public static function algosArgv(): array
    {
        return \phpc_hash_algos_kernel();
    }

    public static function hmacAlgosArgv(): array
    {
        return \phpc_hash_hmac_algos_kernel();
    }
}
