<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * ext/openssl surface advertisement — php-src ext/openssl/openssl.c (#11859).
 *
 * Withhold extension_loaded('openssl') until core entrypoints like
 * openssl_x509_parse() are registered (partial surface stays callable via function_exists).
 */
final class OpensslExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return self::hasX509Parse();
    }

    private static function hasX509Parse(): bool
    {
        return \class_exists(openssl_x509_parse::class);
    }
}
