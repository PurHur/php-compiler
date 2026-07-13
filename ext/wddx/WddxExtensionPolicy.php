<?php

declare(strict_types=1);

namespace PHPCompiler\ext\wddx;

use PHPCompiler\CompilerVersion;

/**
 * ext/wddx surface advertisement — php-src ext/wddx unbundled in PHP 7.4 (#6327).
 *
 * Pure PHP {@see VmWddx} stays compiled in-tree but is withheld from extension_loaded()
 * and function_exists() on the reference profile until {@see CompilerVersion::supportsWddx()}.
 */
final class WddxExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsWddx();
    }
}
