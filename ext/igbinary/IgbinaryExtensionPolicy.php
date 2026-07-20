<?php

declare(strict_types=1);

namespace PHPCompiler\ext\igbinary;

use PHPCompiler\CompilerVersion;

/**
 * ext/igbinary surface advertisement — PECL optional on Zend (#6573, #11993).
 *
 * Pure PHP {@see VmIgbinary} is compiled in-tree but withheld from extension_loaded()
 * and function_exists() on the reference profile until {@see CompilerVersion::supportsIgbinary()}.
 */
final class IgbinaryExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsIgbinary();
    }
}
