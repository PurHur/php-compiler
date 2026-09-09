<?php

declare(strict_types=1);

namespace PHPCompiler\ext\igbinary;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/igbinary surface advertisement — PECL optional on Zend (#6573). Folded to ext.json advertise (#36204).
 */
final class IgbinaryExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('igbinary');
    }
}
