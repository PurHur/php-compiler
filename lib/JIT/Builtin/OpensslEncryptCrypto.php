<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for openssl_encrypt()/openssl_decrypt() (#21065).
 */
final class OpensslEncryptCrypto
{
    public static function ensureLinked(Context $context): void
    {
        OpensslEncryptRuntime::ensureLinked($context);
    }
}
