<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/openssl surface advertisement — php-src ext/openssl/openssl.c (#11859, #16750, #16765).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 *
 * extension_loaded('openssl') matches php-src module_registry once core x509 helpers
 * like openssl_x509_parse() are in-tree (always on this build). Partial crypto builtins may
 * still compile for compliance openssl_extension_phantom.phpt.
 */
final class OpensslExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('openssl');
    }
}
