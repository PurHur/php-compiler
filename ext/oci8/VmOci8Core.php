<?php

declare(strict_types=1);

namespace PHPCompiler\ext\oci8;

/**
 * Shared oci_* semantics (php-src ext/oci8/oci8_interface.c; #6441).
 */
final class VmOci8Core
{
    public static function requireNativeDriver(string $fn): void
    {
        if (Oci8ExtensionPolicy::hasNativeDriver()) {
            return;
        }

        throw new \Error(
            $fn.'(): Oracle OCI8 extension requires Oracle Instant Client (libclntsh) — not available in this build'
        );
    }
}
