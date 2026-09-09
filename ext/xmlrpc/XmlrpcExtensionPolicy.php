<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/xmlrpc surface advertisement — removed in PHP 8.0 (#18503). Folded to ext.json advertise (#36204).
 */
final class XmlrpcExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('xmlrpc');
    }
}
