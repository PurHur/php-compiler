<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for openssl_digest() (#21081).
 */
final class OpensslDigestCrypto
{
    public static function ensureLinked(Context $context): void
    {
        OpensslDigestRuntime::ensureLinked($context);
    }
}
