<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/sodium surface advertisement — php-src ext/sodium/sodium.c (#13078).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise.probe →
 * {@see ExtensionRegistry::advertisesExtensionFor()} → {@see VmSodium::available()} (#36204).
 * AEGIS feature gates stay here (php-src #ifdef crypto_aead_aegis*_KEYBYTES; #20518).
 */
final class SodiumExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('sodium');
    }

    /** AEGIS-128L AEAD — php-src #ifdef crypto_aead_aegis128l_KEYBYTES (#20518). */
    public static function advertisesAegis128l(): bool
    {
        return self::advertisesExtension() && VmSodium::aeadAegis128lAvailable();
    }

    /** AEGIS-256 AEAD — php-src #ifdef crypto_aead_aegis256_KEYBYTES (#20518). */
    public static function advertisesAegis256(): bool
    {
        return self::advertisesExtension() && VmSodium::aeadAegis256Available();
    }

    /** Run AEGIS compliance when symbols advertise or a gated/phantom guard matches (#20518). */
    public static function runsAegisCompliance(string $testFileName): bool
    {
        if (self::advertisesAegis128l() || self::advertisesAegis256()) {
            return true;
        }

        return str_contains($testFileName, 'aegis_gated')
            || str_contains($testFileName, 'aegis_phantom');
    }
}
