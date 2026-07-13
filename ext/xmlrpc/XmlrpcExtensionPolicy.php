<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\CompilerVersion;

/**
 * ext/xmlrpc surface advertisement — php-src ext/xmlrpc/xmlrpc.c removed in PHP 8.0 (#18503).
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
