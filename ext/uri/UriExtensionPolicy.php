<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uri;

use PHPCompiler\CompilerVersion;

/**
 * ext/uri surface advertisement — php-src ext/uri/ (#9051, #17830, #26254).
 *
 * PHP 8.5+ only: withheld on reference profile and PROFILE≤8.4 (Zend 8.4 has no ext/uri).
 * Enable via stable 8.5.0+ or {@code PHP_COMPILER_PROFILE=8.5}.
 */
final class UriExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsUri();
    }
}
