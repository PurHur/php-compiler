<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for password_hash / password_verify / crypt runtime (#6906, #9908).
 *
 * PHP lowering via {@see PasswordCryptoRuntime} → {@see \PHPCompiler\ext\standard\PasswordJitHelper} (#13869).
 */
final class StringPasswordCrypto
{
    public static function ensureLinked(Context $context): void
    {
        PasswordCryptoRuntime::ensureLinked($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        PasswordCryptoRuntime::ensureStandaloneBodies($context);
    }
}
