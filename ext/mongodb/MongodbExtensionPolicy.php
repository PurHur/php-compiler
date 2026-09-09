<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/mongodb surface advertisement — PECL mongodb (#6575). Folded to ext.json advertise (#36204).
 */
final class MongodbExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('mongodb');
    }
}
