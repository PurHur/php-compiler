<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for password_hash / password_verify / crypt runtime (#6906, #9908).
 *
 * Replaces lib/AOT/runtime/password_crypto.c via PasswordCryptoRuntime PHP helpers.
 */
final class StringPasswordCrypto
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        PasswordCryptoRuntime::ensureStandaloneBodies($context);
    }

    public static function implement(Context $context): void
    {
        PasswordCryptoRuntime::implement($context);
    }

    /** MCJIT resolves libcrypt symbols from the host process (#172). */
    public static function preloadLibcrypt(): void
    {
        static $loaded = 0;
        if ($loaded) {
            return;
        }
        if (!\extension_loaded('FFI')) {
            return;
        }
        $selfHost = getenv('PHP_COMPILER_SELFHOST_AOT');
        if ('1' === $selfHost || 'true' === strtolower((string) $selfHost)) {
            $loaded = 1;

            return;
        }
        try {
            $dl = \FFI::cdef('void *dlopen(const char *filename, int flags);', 'libdl.so.2');
            $dl->dlopen('libcrypt.so.1', 0x101);
        } catch (\Throwable $e) {
            // Best-effort: AOT links -lcrypt explicitly.
        }
        $loaded = 1;
    }
}
