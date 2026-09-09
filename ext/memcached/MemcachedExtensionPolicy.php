<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/memcached surface advertisement — PECL php-memcached (#6099). Folded to ext.json advertise (#36204).
 */
final class MemcachedExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('memcached');
    }
}
