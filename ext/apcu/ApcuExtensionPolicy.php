<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\ExtensionRegistry;

/**
 * PECL apcu surface advertisement (#6574, #24909). Folded to ext.json advertise (#36204).
 */
final class ApcuExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('apcu');
    }
}
