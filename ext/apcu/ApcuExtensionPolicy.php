<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\CompilerVersion;

/**
 * PECL apcu surface advertisement — php/pecl-APCu optional on Zend (#6574, #24909).
 *
 * Pure PHP {@see VmApcu} stays compiled in-tree but is withheld from extension_loaded()
 * and function_exists('apcu_*') on the reference profile until
 * {@see CompilerVersion::supportsApcu()}.
 */
final class ApcuExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('apcu')) {
            return true;
        }

        return CompilerVersion::supportsApcu();
    }
}
