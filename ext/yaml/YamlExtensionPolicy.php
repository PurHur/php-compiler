<?php

declare(strict_types=1);

namespace PHPCompiler\ext\yaml;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/yaml surface advertisement — PECL yaml (#6275). Folded to ext.json advertise (#36204).
 */
final class YamlExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('yaml');
    }
}
