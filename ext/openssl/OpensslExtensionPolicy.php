<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * ext/openssl surface advertisement — php-src ext/openssl/openssl.c (#11859, #16750).
 *
 * extension_loaded('openssl') tracks the in-tree openssl module once core crypto
 * entrypoints register (php-src module_registry), not a single optional helper.
 */
final class OpensslExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return self::hasCoreSurface();
    }

    private static function hasCoreSurface(): bool
    {
        return \class_exists(openssl_encrypt::class);
    }
}
