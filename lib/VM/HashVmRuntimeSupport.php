<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Context as JitContext;

/**
 * Static bridge for ext/hash NestedJIT EVP leaf emission (#36204).
 *
 * lib/JIT/Builtin/StringHashCryptoPhp must not import PHPCompiler\ext\hash;
 * Module::init registers {@see ensureEvpLeaves}.
 *
 * php-src: ext/hash/hash.c — PHP_FUNCTION(hash) / HMAC / PBKDF2 / HKDF via EVP.
 */
final class HashVmRuntimeSupport
{
    /** @var null|callable(JitContext): void */
    private static $ensureEvpLeaves = null;

    public static function clear(): void
    {
        self::$ensureEvpLeaves = null;
    }

    /** @param callable(JitContext): void $hook */
    public static function setEnsureEvpLeaves(callable $hook): void
    {
        self::$ensureEvpLeaves = $hook;
    }

    public static function ensureEvpLeaves(JitContext $context): void
    {
        if (null === self::$ensureEvpLeaves) {
            return;
        }

        (self::$ensureEvpLeaves)($context);
    }
}
