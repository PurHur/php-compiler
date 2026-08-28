<?php

declare(strict_types=1);

namespace PHPCompiler\ext\oci8;

/**
 * ext/oci8 surface advertisement — Oracle OCI8 (#6441).
 *
 * oci_* symbols are always registered so {@code function_exists('oci_connect')}
 * matches enterprise apps that probe before connecting. Live Oracle I/O requires
 * Oracle Instant Client / libclntsh; without it, connect raises a catchable
 * {@see \Error} (php-src ext/oci8/oci8.c).
 */
final class Oci8ExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return true;
    }

    public static function advertisesBuiltins(): bool
    {
        return true;
    }

    public static function hasNativeDriver(): bool
    {
        return \function_exists('\\oci_connect');
    }

    public static function isOci8ComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'oci8');
    }
}
