<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\HashAlgosRegistry;

/**
 * hash_algos() / hash_hmac_algos() for compiled JIT/AOT modules (#14909, #18908, #20652, #28750, php-in-PHP).
 *
 * Always NestedJIT'd via {@see \PHPCompiler\JIT\JitVmHelperLink} (no thin registry fork).
 * NestedJIT-safe: return {@see HashAlgosRegistry} list literals (password_algos #9908 shape) —
 * no NestedJIT registry kernel / LLVM hashtable-build leaf re-entry (#28750).
 *
 * Return type is `array` (not {@see HashTable}): NestedJIT maps class HashTable to object
 * ABI and TypeErrors on the pointer (#20652). `array` → `__hashtable__*`.
 *
 * SSOT for VM: {@see \PHPCompiler\ext\standard\VmHash::algos()} / {@see \PHPCompiler\ext\standard\VmHash::hmacAlgos()}
 * php-src: ext/hash/hash.c — PHP_FUNCTION(hash_algos) / PHP_FUNCTION(hash_hmac_algos)
 */
final class HashAlgosJitHelper
{
    /**
     * @return list<string>
     */
    public static function algosArgv(): array
    {
        return HashAlgosRegistry::ALL_ALGOS;
    }

    /**
     * @return list<string>
     */
    public static function hmacAlgosArgv(): array
    {
        return HashAlgosRegistry::HMAC_ALGOS;
    }
}
