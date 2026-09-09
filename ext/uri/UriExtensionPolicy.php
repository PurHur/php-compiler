<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uri;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/uri surface advertisement — php-src ext/uri/ (#9051). Folded to ext.json advertise (#36204).
 */
final class UriExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('uri');
    }
}
