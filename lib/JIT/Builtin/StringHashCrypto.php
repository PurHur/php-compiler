<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for hash() / hash_hmac() / hash_pbkdf2() / hash_equals() / hash_hmac_algos().
 *
 * LLVM lowering via {@see StringHashCryptoJit}.
 */
final class StringHashCrypto
{
    public static function ensureLinked(Context $context): void
    {
        StringHashCryptoJit::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        StringHashCryptoJit::ensureStandaloneBodies($context);
    }

    public static function implement(Context $context): void
    {
        StringHashCryptoJit::implement($context);
    }
}
