<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\CompilerVersion;

/**
 * ext/xmlrpc surface advertisement — php-src ext/xmlrpc/xmlrpc.c PECL optional module (#18503, #6579).
 *
 * Pure PHP {@see VmXmlrpc} stays compiled in-tree but is withheld from extension_loaded()
 * and function_exists() on the reference profile until {@see CompilerVersion::supportsXmlrpc()}.
 */
final class XmlrpcExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsXmlrpc();
    }
}
