<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/xsl surface advertisement — php-src ext/xsl/php_xsl.c (#3665).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 *
 * v1 delegates transforms to host ext/xsl/libxslt; withhold introspection when the
 * harness PHP build lacks libxslt (php-src-strict parity).
 */
final class XslExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('xsl');
    }
}
