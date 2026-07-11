<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/**
 * ext/sodium surface advertisement — php-src ext/sodium/sodium.c (#13078).
 *
 * Register extension + symbols only when libsodium is reachable (no phantom true).
 */
final class SodiumExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return VmSodium::available();
    }
}
