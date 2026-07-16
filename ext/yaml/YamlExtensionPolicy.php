<?php

declare(strict_types=1);

namespace PHPCompiler\ext\yaml;

use PHPCompiler\CompilerVersion;

/**
 * ext/yaml surface advertisement — PECL yaml / php-src-style yaml.c (#6275).
 *
 * Pure PHP {@see VmYaml} stays compiled in-tree but is withheld from extension_loaded()
 * and function_exists() on the reference profile until {@see CompilerVersion::supportsYaml()}.
 */
final class YamlExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsYaml();
    }
}
