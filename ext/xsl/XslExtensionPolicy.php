<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

/**
 * ext/xsl surface advertisement — php-src ext/xsl/php_xsl.c (#3665).
 *
 * v1 delegates transforms to host ext/xsl/libxslt; withhold introspection when the
 * harness PHP build lacks libxslt (php-src-strict parity).
 */
final class XslExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return XsltHostBridge::available();
    }
}
