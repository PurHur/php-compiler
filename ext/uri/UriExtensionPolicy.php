<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uri;

use PHPCompiler\CompilerVersion;

/**
 * ext/uri surface advertisement — php-src ext/uri/ (#9051).
 *
 * PHP 8.4+ ships ext/uri; register when the compiler targets PHP 8.4+.
 */
final class UriExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return version_compare(CompilerVersion::VERSION, '8.4', '>=');
    }
}
