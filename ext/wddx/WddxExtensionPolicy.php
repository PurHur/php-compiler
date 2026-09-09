<?php

declare(strict_types=1);

namespace PHPCompiler\ext\wddx;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/wddx surface advertisement — unbundled in PHP 7.4 (#6327). Folded to ext.json advertise (#36204).
 */
final class WddxExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('wddx');
    }
}
