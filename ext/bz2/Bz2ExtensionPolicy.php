<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\CompilerVersion;

/**
 * ext/bz2 surface advertisement — php-src ext/bz2/bz2.c module registration (#11992, #14219).
 *
 * Pure PHP {@see VmBz2Core} stays compiled in-tree but is withheld from extension_loaded()
 * and function_exists() on the reference profile until {@see CompilerVersion::supportsBz2()}.
 */
final class Bz2ExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsBz2() && VmBz2Native::available();
    }
}
