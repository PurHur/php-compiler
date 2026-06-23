<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hash_equals() timing-safe compare for compiled JIT/AOT modules (#9164, php-in-PHP).
 *
 * SSOT: {@see VmHash::equals()}
 * php-src: ext/hash/hash.c — PHP_HASH_HMAC
 */
final class HashEqualsJitHelper
{
    /** @return bool LLVM i1 ABI; bridge zext to i32 for __compiler_hash_equals */
    public static function equals(string $known, string $user): bool
    {
        return VmHash::equals($known, $user);
    }
}
