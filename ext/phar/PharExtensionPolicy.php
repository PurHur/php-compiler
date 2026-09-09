<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/phar advertisement — php-src ext/phar/phar.c (#3436). Folded to ext.json advertise (#36204).
 */
final class PharExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('phar');
    }
}
