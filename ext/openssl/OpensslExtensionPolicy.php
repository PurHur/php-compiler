<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * ext/openssl surface advertisement — php-src ext/openssl/openssl.c (#11859, #16750, #16765).
 *
 * extension_loaded('openssl') matches php-src module_registry: withhold until core
 * x509 helpers like openssl_x509_parse() register. Partial crypto builtins may still
 * compile in-tree (compliance openssl_extension_phantom.phpt).
 */
final class OpensslExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return self::hasCoreSurface() && self::hasX509ParseSurface();
    }

    private static function hasCoreSurface(): bool
    {
        return \class_exists(openssl_encrypt::class);
    }

    /** php-src ties x509_parse to the openssl module bucket (#11859). */
    private static function hasX509ParseSurface(): bool
    {
        return \class_exists('PHPCompiler\\ext\\openssl\\openssl_x509_parse');
    }
}
