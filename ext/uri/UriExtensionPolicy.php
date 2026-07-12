<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uri;

use PHPCompiler\CompilerVersion;

/**
 * ext/uri surface advertisement — php-src ext/uri/ (#9051, #17830).
 *
 * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 extension_loaded gate).
 * Enable forward profile via {@code PHP_COMPILER_PROFILE=8.4}.
 */
final class UriExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsUri();
    }
}
