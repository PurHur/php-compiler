<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for openssl_get_cipher_methods()/openssl_get_md_methods() (#21103).
 */
final class OpensslMethodsCrypto
{
    public static function ensureLinked(Context $context): void
    {
        OpensslMethodsRuntime::ensureLinked($context);
    }
}
