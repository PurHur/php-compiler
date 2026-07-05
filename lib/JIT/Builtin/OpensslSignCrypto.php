<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for openssl_sign()/openssl_verify() (#3324).
 */
final class OpensslSignCrypto
{
    public static function ensureLinked(Context $context): void
    {
        OpensslSignRuntime::ensureLinked($context);
    }
}
